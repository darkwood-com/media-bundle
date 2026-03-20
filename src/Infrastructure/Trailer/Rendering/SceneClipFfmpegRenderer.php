<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Rendering;

use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Enum\SceneStatus;
use App\Domain\Trailer\Scene;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds a reviewable scenes/<n>-<id>/scene.mp4 via FFmpeg: mux video + voice when both are usable,
 * or copy silent video only. Skips when the scene is not completed, video is missing, not decodable,
 * or FFmpeg fails. Errors are contained per scene (no throw).
 */
final class SceneClipFfmpegRenderer
{
    public function __construct(
        private readonly LocalArtifactStorage $artifactStorage,
        private readonly string $ffmpegBinary = 'ffmpeg',
        private readonly string $ffprobeBinary = 'ffprobe',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Inspect an existing scene without writing: uses domain status, video assets, and scene.mp4 on disk.
     */
    public function classifySceneClip(string $projectId, Scene $scene): SceneClipRenderReport
    {
        if ($scene->status() !== SceneStatus::Completed) {
            return $this->report(
                $scene,
                SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
            );
        }

        $videoPath = $this->resolveUsableVideoPath($scene);
        if ($videoPath === null) {
            return $this->report(
                $scene,
                SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO,
            );
        }

        if (!$this->hasVideoStreamViaFfprobe($videoPath)) {
            return $this->report(
                $scene,
                SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO,
                ['reason' => 'video_asset_not_decodable'],
            );
        }

        $clipPath = $this->artifactStorage->getSceneClipOutputPath($projectId, $scene);
        if (!$this->isUsableSceneMp4File($clipPath)) {
            return $this->report(
                $scene,
                SceneClipRenderReport::OUTCOME_SKIPPED_SCENE_MP4_NOT_USABLE,
            );
        }

        if ($this->hasAudioStreamViaFfprobe($clipPath)) {
            return $this->report($scene, SceneClipRenderReport::OUTCOME_RENDERED_WITH_VOICE);
        }

        return $this->report($scene, SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY);
    }

    public function renderIfPossible(string $projectId, Scene $scene): SceneClipRenderReport
    {
        try {
            return $this->renderIfPossibleInner($projectId, $scene);
        } catch (\Throwable $e) {
            $this->logger->warning('Trailer scene clip: unexpected error', [
                'project_id' => $projectId,
                'scene_id' => $scene->id(),
                'scene_number' => $scene->number(),
                'exception' => $e->getMessage(),
            ]);

            return $this->report($scene, SceneClipRenderReport::OUTCOME_SKIPPED_FFMPEG_FAILED, [
                'reason' => 'unexpected',
            ]);
        }
    }

    private function renderIfPossibleInner(string $projectId, Scene $scene): SceneClipRenderReport
    {
        if ($scene->status() !== SceneStatus::Completed) {
            $this->logger->info('Trailer scene clip: skipped (scene not completed)', [
                'project_id' => $projectId,
                'scene_id' => $scene->id(),
                'scene_number' => $scene->number(),
                'outcome' => SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
            ]);

            return $this->report($scene, SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED);
        }

        $videoPath = $this->resolveUsableVideoPath($scene);
        if ($videoPath === null) {
            $this->logger->info('Trailer scene clip: skipped (no usable video asset on disk)', [
                'project_id' => $projectId,
                'scene_id' => $scene->id(),
                'scene_number' => $scene->number(),
                'outcome' => SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO,
            ]);

            return $this->report($scene, SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO);
        }

        if (!$this->hasVideoStreamViaFfprobe($videoPath)) {
            $this->logger->info('Trailer scene clip: skipped (video asset not decodable)', [
                'project_id' => $projectId,
                'scene_id' => $scene->id(),
                'scene_number' => $scene->number(),
                'outcome' => SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO,
            ]);

            return $this->report(
                $scene,
                SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO,
                ['reason' => 'video_asset_not_decodable'],
            );
        }

        $voicePath = $this->resolveUsableVoicePath($scene);
        $outputPath = $this->artifactStorage->getSceneClipOutputPath($projectId, $scene);
        $dir = \dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $tmpPath = $dir . '/scene.mp4.part.' . bin2hex(random_bytes(8)) . '.mp4';

        try {
            if ($voicePath !== null) {
                if (!$this->muxVideoAndAudio($videoPath, $voicePath, $tmpPath)) {
                    if (is_file($tmpPath)) {
                        unlink($tmpPath);
                    }
                    if ($this->copyVideoOnly($videoPath, $tmpPath)) {
                        $this->logRendered($projectId, $scene, SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY, [
                            'voice_mux_failed' => true,
                        ]);
                        if (!rename($tmpPath, $outputPath)) {
                            if (is_file($tmpPath)) {
                                unlink($tmpPath);
                            }

                            return $this->ffmpegFailedReport($projectId, $scene);
                        }
                        $tmpPath = '';

                        return $this->report(
                            $scene,
                            SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY,
                            ['voice_mux_failed' => true],
                        );
                    }

                    return $this->ffmpegFailedReport($projectId, $scene);
                }
            } else {
                if (!$this->copyVideoOnly($videoPath, $tmpPath)) {
                    return $this->ffmpegFailedReport($projectId, $scene);
                }
            }

            if (!rename($tmpPath, $outputPath)) {
                if (is_file($tmpPath)) {
                    unlink($tmpPath);
                }

                return $this->ffmpegFailedReport($projectId, $scene);
            }
            $tmpPath = '';

            $outcome = $voicePath !== null
                ? SceneClipRenderReport::OUTCOME_RENDERED_WITH_VOICE
                : SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY;
            $this->logRendered($projectId, $scene, $outcome, []);

            return $this->report($scene, $outcome);
        } finally {
            if ($tmpPath !== '' && is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    private function ffmpegFailedReport(string $projectId, Scene $scene): SceneClipRenderReport
    {
        $this->logger->warning('Trailer scene clip: skipped (ffmpeg failed)', [
            'project_id' => $projectId,
            'scene_id' => $scene->id(),
            'scene_number' => $scene->number(),
            'outcome' => SceneClipRenderReport::OUTCOME_SKIPPED_FFMPEG_FAILED,
        ]);

        return $this->report($scene, SceneClipRenderReport::OUTCOME_SKIPPED_FFMPEG_FAILED);
    }

    /**
     * @param array<string, bool|string|int|float|null> $details
     */
    private function report(Scene $scene, string $outcome, array $details = []): SceneClipRenderReport
    {
        return new SceneClipRenderReport($scene->id(), $scene->number(), $outcome, $details);
    }

    /**
     * @param array<string, bool|string|int|float|null> $details
     */
    private function logRendered(string $projectId, Scene $scene, string $outcome, array $details): void
    {
        $this->logger->info('Trailer scene clip: rendered', array_merge([
            'project_id' => $projectId,
            'scene_id' => $scene->id(),
            'scene_number' => $scene->number(),
            'outcome' => $outcome,
        ], $details));
    }

    private function isUsableSceneMp4File(string $path): bool
    {
        if (!is_file($path) || filesize($path) < 1) {
            return false;
        }

        return $this->hasVideoStreamViaFfprobe($path);
    }

    private function resolveUsableVideoPath(Scene $scene): ?string
    {
        $candidates = [];
        foreach ($scene->assets() as $asset) {
            if ($asset->type() !== AssetType::Video || $asset->status() !== AssetStatus::Completed) {
                continue;
            }
            if (($asset->metadata()['skipped'] ?? false) === true) {
                continue;
            }
            $p = $asset->path();
            if ($p === null || !is_file($p) || filesize($p) < 1) {
                continue;
            }
            $candidates[] = $p;
        }

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $p) {
            if (basename($p) === 'video.mp4') {
                return $p;
            }
        }

        return $candidates[0];
    }

    private function resolveUsableVoicePath(Scene $scene): ?string
    {
        foreach ($scene->assets() as $asset) {
            if ($asset->type() !== AssetType::Voice || $asset->status() !== AssetStatus::Completed) {
                continue;
            }
            if (($asset->metadata()['skipped'] ?? false) === true) {
                return null;
            }
            $p = $asset->path();
            if ($p === null || !is_file($p) || filesize($p) < 1) {
                return null;
            }

            return $p;
        }

        return null;
    }

    private function copyVideoOnly(string $videoPath, string $outputPath): bool
    {
        return $this->runSuccessful([
            $this->ffmpegBinary,
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-i',
            $videoPath,
            '-c',
            'copy',
            '-f',
            'mp4',
            $outputPath,
        ]);
    }

    private function muxVideoAndAudio(string $videoPath, string $voicePath, string $outputPath): bool
    {
        $copy = [
            $this->ffmpegBinary,
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-i',
            $videoPath,
            '-i',
            $voicePath,
            '-map',
            '0:v:0',
            '-map',
            '1:a:0',
            '-c',
            'copy',
            '-shortest',
            '-f',
            'mp4',
            $outputPath,
        ];
        if ($this->runSuccessful($copy)) {
            return true;
        }

        return $this->runSuccessful([
            $this->ffmpegBinary,
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-i',
            $videoPath,
            '-i',
            $voicePath,
            '-map',
            '0:v:0',
            '-map',
            '1:a:0',
            '-c:v',
            'copy',
            '-c:a',
            'aac',
            '-b:a',
            '192k',
            '-shortest',
            '-f',
            'mp4',
            $outputPath,
        ]);
    }

    private function hasVideoStreamViaFfprobe(string $path): bool
    {
        return $this->runProcessZero([
            $this->ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=index',
            '-of',
            'csv=p=0',
            $path,
        ]);
    }

    private function hasAudioStreamViaFfprobe(string $path): bool
    {
        return $this->runProcessZero([
            $this->ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=index',
            '-of',
            'csv=p=0',
            $path,
        ]);
    }

    /**
     * @param list<string> $command
     */
    private function runSuccessful(array $command): bool
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorspec, $pipes, null, null);
        if (!\is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return $code === 0 && is_file($command[\count($command) - 1]);
    }

    /**
     * @param list<string> $command
     */
    private function runProcessZero(array $command): bool
    {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorspec, $pipes, null, null);
        if (!\is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }
}

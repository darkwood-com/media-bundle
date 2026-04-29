<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Rendering;

use App\Domain\Video\Enum\SceneStatus;
use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;
use App\Infrastructure\Video\Storage\LocalArtifactStorage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds render/scenario.mp4 by FFmpeg concat demuxer (single sequential pass).
 * Call only after all scene flows have finished — typically from {@see \App\Flow\FinalizeVideoProjectFlow}.
 * Scenes are concatenated in ascending {@see Scene::number()} order. Only {@see SceneStatus::Completed}
 * scenes with a valid on-disk scene.mp4 are included; others are excluded without failing the whole concat.
 */
final class ScenarioConcatFfmpegRenderer
{
    public function __construct(
        private readonly LocalArtifactStorage $artifactStorage,
        private readonly string $ffmpegBinary = 'ffmpeg',
        private readonly string $ffprobeBinary = 'ffprobe',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function concatIfPossible(string $projectId, VideoProject $project): ScenarioConcatResult
    {
        try {
            return $this->concatIfPossibleInner($projectId, $project);
        } catch (\Throwable $e) {
            return new ScenarioConcatResult(
                null,
                'Scenario concat failed unexpectedly: ' . $e->getMessage(),
                [],
                [],
            );
        }
    }

    private function concatIfPossibleInner(string $projectId, VideoProject $project): ScenarioConcatResult
    {
        $clipPaths = [];
        $excludedFromScenario = [];
        $stagedIncluded = [];

        $scenes = $project->scenes();
        usort($scenes, static fn (Scene $a, Scene $b): int => $a->number() <=> $b->number());

        foreach ($scenes as $scene) {
            if ($scene->status() !== SceneStatus::Completed) {
                $excludedFromScenario[] = [
                    'scene_number' => $scene->number(),
                    'scene_id' => $scene->id(),
                    'reason' => 'scene_not_completed',
                ];
                $this->logger->info('Video scenario concat: scene excluded (not completed)', [
                    'project_id' => $projectId,
                    'scene_id' => $scene->id(),
                    'scene_number' => $scene->number(),
                ]);

                continue;
            }

            $path = $this->artifactStorage->getSceneClipOutputPath($projectId, $scene);
            if ($this->isValidSceneClip($path)) {
                $clipPaths[] = $path;
                $stagedIncluded[] = [
                    'scene_number' => $scene->number(),
                    'scene_id' => $scene->id(),
                ];
            } else {
                $reason = $this->sceneMp4ExclusionReason($path);
                $excludedFromScenario[] = [
                    'scene_number' => $scene->number(),
                    'scene_id' => $scene->id(),
                    'reason' => $reason,
                ];
                $this->logger->info('Video scenario concat: scene excluded from scenario (scene.mp4 not usable)', [
                    'project_id' => $projectId,
                    'scene_id' => $scene->id(),
                    'scene_number' => $scene->number(),
                    'reason' => $reason,
                ]);
            }
        }

        if ($clipPaths === []) {
            $this->removeScenarioOutputIfExists($projectId);

            return new ScenarioConcatResult(
                null,
                sprintf(
                    'Skipped scenario.mp4: no valid scene.mp4 clips for project %s (missing, empty, or no decodable video stream).',
                    $projectId,
                ),
                [],
                $excludedFromScenario,
            );
        }

        $outputPath = $this->artifactStorage->getScenarioOutputPath($projectId);
        $renderDir = \dirname($outputPath);
        if (!is_dir($renderDir)) {
            mkdir($renderDir, 0o755, true);
        }

        $listPath = $renderDir . '/scenario-concat.' . bin2hex(random_bytes(8)) . '.txt';
        $tmpOut = $renderDir . '/scenario.mp4.part.' . bin2hex(random_bytes(8)) . '.mp4';

        try {
            $this->writeConcatListFile($listPath, $clipPaths);

            $copyOk = $this->runConcatFfmpeg($listPath, $tmpOut, reencode: false);
            if (!$copyOk) {
                if (is_file($tmpOut)) {
                    unlink($tmpOut);
                }
                if (!$this->runConcatFfmpeg($listPath, $tmpOut, reencode: true)) {
                    if (is_file($tmpOut)) {
                        unlink($tmpOut);
                    }

                    return new ScenarioConcatResult(
                        null,
                        sprintf(
                            'Skipped scenario.mp4: FFmpeg could not concatenate %d valid scene clip(s) for project %s.',
                            \count($clipPaths),
                            $projectId,
                        ),
                        [],
                        $excludedFromScenario,
                    );
                }
            }

            if (!rename($tmpOut, $outputPath)) {
                if (is_file($tmpOut)) {
                    unlink($tmpOut);
                }

                return new ScenarioConcatResult(
                    null,
                    'Skipped scenario.mp4: could not finalize output file (rename failed).',
                    [],
                    $excludedFromScenario,
                );
            }
            $tmpOut = '';

            foreach ($stagedIncluded as $row) {
                $this->logger->info('Video scenario concat: scene included in scenario.mp4', [
                    'project_id' => $projectId,
                    'scene_id' => $row['scene_id'],
                    'scene_number' => $row['scene_number'],
                ]);
            }

            return new ScenarioConcatResult($outputPath, null, $stagedIncluded, $excludedFromScenario);
        } finally {
            if (is_file($listPath)) {
                unlink($listPath);
            }
            if ($tmpOut !== '' && is_file($tmpOut)) {
                unlink($tmpOut);
            }
        }
    }

    private function removeScenarioOutputIfExists(string $projectId): void
    {
        $p = $this->artifactStorage->getScenarioOutputPath($projectId);
        if (is_file($p)) {
            unlink($p);
        }
    }

    /**
     * @param list<string> $absolutePaths
     */
    private function writeConcatListFile(string $listPath, array $absolutePaths): void
    {
        $lines = '';
        foreach ($absolutePaths as $abs) {
            $resolved = realpath($abs);
            $path = $resolved !== false ? $resolved : $abs;
            $path = str_replace('\\', '/', $path);
            $escaped = str_replace("'", "'\\''", $path);
            $lines .= "file '" . $escaped . "'\n";
        }

        file_put_contents($listPath, $lines);
    }

    private function runConcatFfmpeg(string $listPath, string $outputPath, bool $reencode): bool
    {
        $cmd = [
            $this->ffmpegBinary,
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $listPath,
        ];
        if ($reencode) {
            array_push(
                $cmd,
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-crf',
                '23',
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-b:a',
                '192k',
                '-movflags',
                '+faststart',
            );
        } else {
            $cmd[] = '-c';
            $cmd[] = 'copy';
        }
        $cmd[] = $outputPath;

        return $this->runProcessZero($cmd) && is_file($outputPath) && filesize($outputPath) >= 1;
    }

    private function sceneMp4ExclusionReason(string $path): string
    {
        if (!is_file($path)) {
            return 'scene_mp4_missing';
        }
        if (filesize($path) < 1) {
            return 'scene_mp4_empty';
        }

        return 'scene_mp4_not_decodable';
    }

    private function isValidSceneClip(string $path): bool
    {
        if (!is_file($path) || filesize($path) < 1) {
            return false;
        }

        return $this->hasVideoStreamViaFfprobe($path);
    }

    private function hasVideoStreamViaFfprobe(string $path): bool
    {
        $cmd = [
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
        ];

        return $this->runProcessZero($cmd);
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

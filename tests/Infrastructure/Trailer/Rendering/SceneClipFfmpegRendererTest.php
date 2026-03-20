<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Rendering;

use App\Domain\Trailer\Asset;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Scene;
use App\Infrastructure\Trailer\Rendering\SceneClipFfmpegRenderer;
use App\Infrastructure\Trailer\Rendering\SceneClipRenderReport;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use App\Infrastructure\Trailer\Storage\TrailerPathResolver;
use PHPUnit\Framework\TestCase;

final class SceneClipFfmpegRendererTest extends TestCase
{
    public function test_skips_when_scene_not_completed(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scene-clip-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new SceneClipFfmpegRenderer($storage, 'ffmpeg');

            $scene = $this->makeSceneWithVideoOnly($tmp, 'proj-a', '1-moon', AssetStatus::Processing);
            $report = $renderer->renderIfPossible('proj-a', $scene);

            self::assertSame(SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED, $report->outcome);
            self::assertFileDoesNotExist($storage->getSceneClipOutputPath('proj-a', $scene));
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_classify_skips_when_scene_not_completed(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scene-clip-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new SceneClipFfmpegRenderer($storage, 'ffmpeg', '/bin/false');

            $scene = $this->makeSceneWithVideoOnly($tmp, 'proj-z', '1-moon', AssetStatus::Processing);
            $report = $renderer->classifySceneClip('proj-z', $scene);

            self::assertSame(SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED, $report->outcome);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_classify_skips_when_no_usable_video_asset(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scene-clip-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new SceneClipFfmpegRenderer($storage, 'ffmpeg', '/bin/false');

            $scene = new Scene(id: 'moon', number: 1, title: 'Moon');
            $scene->addAsset(new Asset(
                id: 'moon-video',
                sceneId: 'moon',
                type: AssetType::Video,
                status: AssetStatus::Completed,
                path: $tmp . '/nonexistent.mp4',
            ));
            $scene->complete();

            $report = $renderer->classifySceneClip('proj-classify-b', $scene);

            self::assertSame(SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO, $report->outcome);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_classify_skips_when_video_asset_not_decodable_per_ffprobe(): void
    {
        if (!is_executable('/bin/false')) {
            self::markTestSkipped('/bin/false not available');
        }

        $tmp = sys_get_temp_dir() . '/dw-scene-clip-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-classify-probe';
            $sceneDir = $tmp . '/var/trailers/' . $projectId . '/scenes/1-moon/';
            self::assertTrue(mkdir($sceneDir, 0o755, true));
            $videoPath = $sceneDir . 'video.mp4';
            file_put_contents($videoPath, 'not-a-video');

            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new SceneClipFfmpegRenderer($storage, '/bin/false', '/bin/false');

            $scene = new Scene(id: 'moon', number: 1, title: 'Moon');
            $scene->addAsset(new Asset(
                id: 'moon-video',
                sceneId: 'moon',
                type: AssetType::Video,
                status: AssetStatus::Completed,
                path: $videoPath,
            ));
            $scene->complete();

            $report = $renderer->classifySceneClip($projectId, $scene);

            self::assertSame(SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO, $report->outcome);
            self::assertSame('video_asset_not_decodable', $report->details['reason'] ?? null);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_skips_when_no_usable_video_asset(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scene-clip-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new SceneClipFfmpegRenderer($storage, 'ffmpeg');

            $scene = new Scene(id: 'moon', number: 1, title: 'Moon');
            $scene->addAsset(new Asset(
                id: 'moon-video',
                sceneId: 'moon',
                type: AssetType::Video,
                status: AssetStatus::Completed,
                path: $tmp . '/nonexistent.mp4',
            ));
            $scene->complete();

            $report = $renderer->renderIfPossible('proj-b', $scene);

            self::assertSame(SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO, $report->outcome);
            self::assertFileDoesNotExist($storage->getSceneClipOutputPath('proj-b', $scene));
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_writes_scene_mp4_from_video_only_when_ffmpeg_available(): void
    {
        $ffmpeg = self::findFfmpegBinary();
        if ($ffmpeg === null) {
            self::markTestSkipped('ffmpeg not found in PATH');
        }

        $tmp = sys_get_temp_dir() . '/dw-scene-clip-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-c';
            $sceneDir = $tmp . '/var/trailers/' . $projectId . '/scenes/1-moon/';
            self::assertTrue(mkdir($sceneDir, 0o755, true));
            $videoPath = $sceneDir . 'video.mp4';
            $this->assertSame(0, $this->runShell(
                sprintf(
                    '%s -y -nostdin -hide_banner -loglevel error -f lavfi -i testsrc=duration=0.4:size=64x64:rate=10 -pix_fmt yuv420p %s',
                    escapeshellarg($ffmpeg),
                    escapeshellarg($videoPath),
                ),
            ));

            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new SceneClipFfmpegRenderer($storage, $ffmpeg);

            $scene = $this->makeSceneWithVideoOnly($tmp, $projectId, '1-moon', AssetStatus::Completed, $videoPath);
            $report = $renderer->renderIfPossible($projectId, $scene);

            self::assertSame(SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY, $report->outcome);
            $out = $storage->getSceneClipOutputPath($projectId, $scene);
            self::assertFileExists($out);
            self::assertGreaterThan(100, filesize($out));
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_muxes_voice_when_present_and_ffmpeg_available(): void
    {
        $ffmpeg = self::findFfmpegBinary();
        if ($ffmpeg === null) {
            self::markTestSkipped('ffmpeg not found in PATH');
        }

        $tmp = sys_get_temp_dir() . '/dw-scene-clip-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-d';
            $sceneDir = $tmp . '/var/trailers/' . $projectId . '/scenes/1-moon/';
            self::assertTrue(mkdir($sceneDir, 0o755, true));
            $videoPath = $sceneDir . 'video.mp4';
            $voicePath = $sceneDir . 'voice.mp3';

            $this->assertSame(0, $this->runShell(
                sprintf(
                    '%s -y -nostdin -hide_banner -loglevel error -f lavfi -i testsrc=duration=0.6:size=64x64:rate=10 -pix_fmt yuv420p %s',
                    escapeshellarg($ffmpeg),
                    escapeshellarg($videoPath),
                ),
            ));
            $this->assertSame(0, $this->runShell(
                sprintf(
                    '%s -y -nostdin -hide_banner -loglevel error -f lavfi -i sine=frequency=440:duration=0.6 -c:a libmp3lame -q:a 6 %s',
                    escapeshellarg($ffmpeg),
                    escapeshellarg($voicePath),
                ),
            ));

            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new SceneClipFfmpegRenderer($storage, $ffmpeg);

            $scene = new Scene(id: 'moon', number: 1, title: 'Moon');
            $scene->addAsset(new Asset(
                id: 'moon-video',
                sceneId: 'moon',
                type: AssetType::Video,
                status: AssetStatus::Completed,
                path: $videoPath,
            ));
            $scene->addAsset(new Asset(
                id: 'moon-voice',
                sceneId: 'moon',
                type: AssetType::Voice,
                status: AssetStatus::Completed,
                path: $voicePath,
            ));
            $scene->complete();

            $report = $renderer->renderIfPossible($projectId, $scene);

            self::assertSame(SceneClipRenderReport::OUTCOME_RENDERED_WITH_VOICE, $report->outcome);
            $out = $storage->getSceneClipOutputPath($projectId, $scene);
            self::assertFileExists($out);
            self::assertGreaterThan(100, filesize($out));
        } finally {
            $this->removeTree($tmp);
        }
    }

    private static function findFfmpegBinary(): ?string
    {
        $out = shell_exec('command -v ffmpeg 2>/dev/null');
        if (!\is_string($out)) {
            return null;
        }
        $path = trim($out);

        return $path !== '' ? $path : null;
    }

    private function makeSceneWithVideoOnly(
        string $tmp,
        string $projectId,
        string $sceneSegment,
        AssetStatus $videoStatus,
        ?string $videoPath = null,
    ): Scene {
        $scene = new Scene(id: 'moon', number: 1, title: 'Moon');
        $path = $videoPath ?? ($tmp . '/var/trailers/' . $projectId . '/scenes/' . $sceneSegment . '/video.mp4');
        $scene->addAsset(new Asset(
            id: 'moon-video',
            sceneId: 'moon',
            type: AssetType::Video,
            status: $videoStatus,
            path: $path,
        ));
        if ($videoStatus === AssetStatus::Completed) {
            $scene->complete();
        }

        return $scene;
    }

    private function runShell(string $command): int
    {
        exec($command . ' 2>&1', $output, $code);

        return $code;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $p = $file->getPathname();
            $file->isDir() ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Rendering;

use App\Domain\Trailer\Enum\SceneStatus;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;
use App\Infrastructure\Trailer\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use App\Infrastructure\Trailer\Storage\TrailerPathResolver;
use PHPUnit\Framework\TestCase;

final class ScenarioConcatFfmpegRendererTest extends TestCase
{
    public function test_concat_lists_only_valid_clips_included_ffprobe_never_succeeds(): void
    {
        if (!is_executable('/bin/false')) {
            self::markTestSkipped('/bin/false not available');
        }

        $tmp = sys_get_temp_dir() . '/dw-scenario-concat-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-filter';
            $base = $tmp . '/var/trailers/' . $projectId . '/scenes';
            self::assertTrue(mkdir($base . '/1-a', 0o755, true));
            self::assertTrue(mkdir($base . '/2-b', 0o755, true));
            file_put_contents($base . '/2-b/scene.mp4', '');
            self::assertTrue(mkdir($base . '/3-c', 0o755, true));
            file_put_contents($base . '/3-c/scene.mp4', 'garbage');

            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new ScenarioConcatFfmpegRenderer($storage, '/bin/false', '/bin/false');

            $project = new TrailerProject($projectId, '/x.yaml', 'T');
            $project->addScene(new Scene(id: 'a', number: 1, title: 'A', status: SceneStatus::Completed));
            $project->addScene(new Scene(id: 'b', number: 2, title: 'B', status: SceneStatus::Completed));
            $project->addScene(new Scene(id: 'c', number: 3, title: 'C', status: SceneStatus::Completed));

            $result = $renderer->concatIfPossible($projectId, $project);

            self::assertNull($result->outputPath);
            self::assertNotNull($result->skipReason);
            self::assertSame([], $result->scenesIncludedInScenario);
            self::assertCount(3, $result->scenesExcludedFromScenario);
            self::assertSame('scene_mp4_missing', $result->scenesExcludedFromScenario[0]['reason']);
            self::assertSame('scene_mp4_empty', $result->scenesExcludedFromScenario[1]['reason']);
            self::assertSame('scene_mp4_not_decodable', $result->scenesExcludedFromScenario[2]['reason']);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_skips_when_no_valid_scene_clips(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scenario-concat-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-empty';
            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new ScenarioConcatFfmpegRenderer($storage, 'ffmpeg', 'ffprobe');

            $project = new TrailerProject($projectId, '/x.yaml', 'T');
            $project->addScene(new Scene(id: 'a', number: 1, title: 'A', status: SceneStatus::Completed));

            $result = $renderer->concatIfPossible($projectId, $project);

            self::assertNull($result->outputPath);
            self::assertNotNull($result->skipReason);
            self::assertStringContainsString('no valid scene.mp4', (string) $result->skipReason);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_removes_stale_scenario_when_no_valid_clips(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scenario-concat-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-stale';
            $renderDir = $tmp . '/var/trailers/' . $projectId . '/render';
            self::assertTrue(mkdir($renderDir, 0o755, true));
            $stale = $renderDir . '/scenario.mp4';
            file_put_contents($stale, 'x');

            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new ScenarioConcatFfmpegRenderer($storage, 'ffmpeg', 'ffprobe');

            $project = new TrailerProject($projectId, '/x.yaml', 'T');
            $project->addScene(new Scene(id: 'a', number: 1, title: 'A', status: SceneStatus::Completed));

            $renderer->concatIfPossible($projectId, $project);

            self::assertFileDoesNotExist($stale);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_skips_empty_scene_mp4_and_concatenates_valid_in_order(): void
    {
        $ffmpeg = self::findFfmpegBinary();
        $ffprobe = self::findFfprobeBinary();
        if ($ffmpeg === null || $ffprobe === null) {
            self::markTestSkipped('ffmpeg and ffprobe required in PATH');
        }

        $tmp = sys_get_temp_dir() . '/dw-scenario-concat-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-mix';
            $base = $tmp . '/var/trailers/' . $projectId . '/scenes';

            self::assertTrue(mkdir($base . '/1-a', 0o755, true));
            $this->assertSame(0, $this->runShell(sprintf(
                '%s -y -nostdin -hide_banner -loglevel error -f lavfi -i testsrc=duration=0.35:size=64x48:rate=10 -pix_fmt yuv420p %s',
                escapeshellarg($ffmpeg),
                escapeshellarg($base . '/1-a/scene.mp4'),
            )));
            self::assertTrue(mkdir($base . '/2-b', 0o755, true));
            file_put_contents($base . '/2-b/scene.mp4', '');
            self::assertTrue(mkdir($base . '/3-c', 0o755, true));
            $this->assertSame(0, $this->runShell(sprintf(
                '%s -y -nostdin -hide_banner -loglevel error -f lavfi -i testsrc=duration=0.35:size=64x48:rate=10 -pix_fmt yuv420p %s',
                escapeshellarg($ffmpeg),
                escapeshellarg($base . '/3-c/scene.mp4'),
            )));

            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new ScenarioConcatFfmpegRenderer($storage, $ffmpeg, $ffprobe);

            $project = new TrailerProject($projectId, '/x.yaml', 'T');
            $project->addScene(new Scene(id: 'a', number: 1, title: 'A', status: SceneStatus::Completed));
            $project->addScene(new Scene(id: 'b', number: 2, title: 'B', status: SceneStatus::Completed));
            $project->addScene(new Scene(id: 'c', number: 3, title: 'C', status: SceneStatus::Completed));

            $result = $renderer->concatIfPossible($projectId, $project);

            self::assertNull($result->skipReason);
            self::assertNotNull($result->outputPath);
            self::assertSame($storage->getScenarioOutputPath($projectId), $result->outputPath);
            self::assertFileExists($result->outputPath);
            self::assertGreaterThan(200, filesize($result->outputPath));
            self::assertCount(2, $result->scenesIncludedInScenario);
            self::assertCount(1, $result->scenesExcludedFromScenario);
            self::assertSame('b', $result->scenesExcludedFromScenario[0]['scene_id']);
            self::assertSame('scene_mp4_empty', $result->scenesExcludedFromScenario[0]['reason']);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_concatenates_in_scene_number_order_when_scenes_added_out_of_order(): void
    {
        $ffmpeg = self::findFfmpegBinary();
        $ffprobe = self::findFfprobeBinary();
        if ($ffmpeg === null || $ffprobe === null) {
            self::markTestSkipped('ffmpeg and ffprobe required in PATH');
        }

        $tmp = sys_get_temp_dir() . '/dw-scenario-concat-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $projectId = 'proj-order';
            $base = $tmp . '/var/trailers/' . $projectId . '/scenes';

            foreach ([['1', 'a'], ['2', 'b'], ['3', 'c']] as [$num, $id]) {
                $dir = $base . '/' . $num . '-' . $id;
                self::assertTrue(mkdir($dir, 0o755, true));
                $this->assertSame(0, $this->runShell(sprintf(
                    '%s -y -nostdin -hide_banner -loglevel error -f lavfi -i testsrc=duration=0.2:size=64x48:rate=10 -pix_fmt yuv420p %s',
                    escapeshellarg($ffmpeg),
                    escapeshellarg($dir . '/scene.mp4'),
                )));
            }

            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $renderer = new ScenarioConcatFfmpegRenderer($storage, $ffmpeg, $ffprobe);

            $project = new TrailerProject($projectId, '/x.yaml', 'T');
            $project->addScene(new Scene(id: 'c', number: 3, title: 'C', status: SceneStatus::Completed));
            $project->addScene(new Scene(id: 'a', number: 1, title: 'A', status: SceneStatus::Completed));
            $project->addScene(new Scene(id: 'b', number: 2, title: 'B', status: SceneStatus::Completed));

            $result = $renderer->concatIfPossible($projectId, $project);

            self::assertNull($result->skipReason);
            self::assertNotNull($result->outputPath);
            self::assertCount(3, $result->scenesIncludedInScenario);
            self::assertSame(1, $result->scenesIncludedInScenario[0]['scene_number']);
            self::assertSame(2, $result->scenesIncludedInScenario[1]['scene_number']);
            self::assertSame(3, $result->scenesIncludedInScenario[2]['scene_number']);
            self::assertSame(['a', 'b', 'c'], array_map(
                static fn (array $row): string => $row['scene_id'],
                $result->scenesIncludedInScenario,
            ));
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

    private static function findFfprobeBinary(): ?string
    {
        $out = shell_exec('command -v ffprobe 2>/dev/null');
        if (!\is_string($out)) {
            return null;
        }
        $path = trim($out);

        return $path !== '' ? $path : null;
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

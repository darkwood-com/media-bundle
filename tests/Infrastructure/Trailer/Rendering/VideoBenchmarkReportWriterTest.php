<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Rendering;

use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Domain\Trailer\Asset;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;
use App\Infrastructure\Trailer\Rendering\VideoBenchmarkReportWriter;
use PHPUnit\Framework\TestCase;

final class VideoBenchmarkReportWriterTest extends TestCase
{
    /** @var list<string> */
    private array $tempRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $dir) {
            $this->removeTree($dir);
        }
        $this->tempRoots = [];
        parent::tearDown();
    }

    public function test_write_returns_null_when_only_one_video_asset(): void
    {
        $renderRoot = sys_get_temp_dir() . '/vbw_' . uniqid('', true);
        $this->tempRoots[] = $renderRoot;

        $setup = $this->createMock(TrailerProjectSetupInterface::class);
        $setup->method('getRenderOutputPath')->willReturn($renderRoot . '/render/final.mp4');

        $writer = new VideoBenchmarkReportWriter($setup);

        $project = new TrailerProject('bench-proj', '/def.yaml', 'T');
        $scene = new Scene('scene-a', 1, 'One', videoPrompt: 'A prompt');
        $scene->addAsset(new Asset(
            id: 'scene-a-video--x',
            sceneId: 'scene-a',
            type: AssetType::Video,
            status: AssetStatus::Completed,
            path: $renderRoot . '/v.mp4',
            metadata: [
                'replicate_preset' => 'hailuo',
                'local_path' => $renderRoot . '/v.mp4',
                'prompt' => 'A prompt',
            ],
        ));
        $project->addScene($scene);

        self::assertNull($writer->writeIfApplicable($project));
    }

    public function test_writes_json_and_markdown_for_multi_video_scene(): void
    {
        $renderRoot = sys_get_temp_dir() . '/vbw_' . uniqid('', true);
        mkdir($renderRoot . '/render', 0755, true);
        $this->tempRoots[] = $renderRoot;

        $setup = $this->createMock(TrailerProjectSetupInterface::class);
        $setup->method('getRenderOutputPath')->willReturn($renderRoot . '/render/final.mp4');

        $writer = new VideoBenchmarkReportWriter($setup);

        $project = new TrailerProject('bench-proj', '/def.yaml', 'T');
        $scene = new Scene('scene-a', 1, 'One', videoPrompt: 'Shared prompt text');
        $scene->addAsset(new Asset(
            id: 'scene-a-video--seedance-1-lite',
            sceneId: 'scene-a',
            type: AssetType::Video,
            status: AssetStatus::Completed,
            path: $renderRoot . '/scenes/1-scene-a/video--seedance-1-lite.mp4',
            metadata: [
                'replicate_preset' => 'seedance',
                'local_path' => $renderRoot . '/scenes/1-scene-a/video--seedance-1-lite.mp4',
                'prompt' => 'Shared prompt text',
                'model' => 'bytedance/seedance-1-lite',
                'generation_time_seconds' => 40.0,
                'metrics' => ['predict_time' => 8.5, 'cost' => 0.02],
            ],
        ));
        $scene->addAsset(new Asset(
            id: 'scene-a-video--hailuo-02-fast',
            sceneId: 'scene-a',
            type: AssetType::Video,
            status: AssetStatus::Completed,
            path: $renderRoot . '/scenes/1-scene-a/video--hailuo-02-fast.mp4',
            metadata: [
                'replicate_preset' => 'hailuo',
                'local_path' => $renderRoot . '/scenes/1-scene-a/video--hailuo-02-fast.mp4',
                'prompt' => 'Shared prompt text',
                'model' => 'minimax/hailuo-02-fast',
                'generation_time_seconds' => 55.25,
            ],
        ));
        $project->addScene($scene);

        $paths = $writer->writeIfApplicable($project);
        self::assertNotNull($paths);
        self::assertSame($renderRoot . '/render/' . VideoBenchmarkReportWriter::JSON_FILENAME, $paths['json']);
        self::assertSame($renderRoot . '/render/' . VideoBenchmarkReportWriter::MD_FILENAME, $paths['markdown']);

        self::assertFileExists($paths['json']);
        self::assertFileExists($paths['markdown']);

        $data = json_decode((string) file_get_contents($paths['json']), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('bench-proj', $data['project_id']);
        self::assertSame('Shared prompt text', $data['shared_video_prompt']);
        self::assertCount(2, $data['models']);

        $md = (string) file_get_contents($paths['markdown']);
        self::assertStringContainsString('# Video benchmark report', $md);
        self::assertStringContainsString('Shared prompt text', $md);
        self::assertStringContainsString('hailuo', $md);
        self::assertStringContainsString('seedance', $md);
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
        foreach ($it as $fileInfo) {
            $path = $fileInfo->getPathname();
            $fileInfo->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

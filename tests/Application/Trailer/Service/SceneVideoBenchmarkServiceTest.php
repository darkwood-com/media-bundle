<?php

declare(strict_types=1);

namespace App\Tests\Application\Trailer\Service;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Application\Trailer\Service\SceneVideoBenchmarkService;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Scene;
use App\Infrastructure\Trailer\Provider\FakeVideoGenerationProvider;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoModelPresets;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use App\Infrastructure\Trailer\Storage\TrailerPathResolver;
use PHPUnit\Framework\TestCase;

final class SceneVideoBenchmarkServiceTest extends TestCase
{
    public function test_generates_one_video_asset_per_preset_same_prompt(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-bench-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $resolver = new TrailerPathResolver($tmp);
            $storage = new LocalArtifactStorage($resolver);
            $provider = new FakeVideoGenerationProvider();
            $service = new SceneVideoBenchmarkService($provider, $storage);

            $scene = new Scene(
                id: 'scene-1',
                number: 1,
                title: 'One',
                videoPrompt: 'cinematic forest',
                narrationText: 'ignored for this test',
            );
            $storage->ensureSceneDirectory('proj', $scene);

            $presets = [ReplicateVideoModelPresets::HAILUO, ReplicateVideoModelPresets::SEEDANCE];
            $ok = $service->generateVideosForPresets('proj', $scene, 'same prompt for all', $presets);

            self::assertTrue($ok);
            $videos = array_values(array_filter(
                $scene->assets(),
                static fn ($a) => $a->type() === AssetType::Video,
            ));
            self::assertCount(2, $videos);
            foreach ($videos as $asset) {
                self::assertNotNull($asset->path());
                self::assertFileExists((string) $asset->path());
            }
            self::assertNotSame($videos[0]->path(), $videos[1]->path());
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_each_preset_asset_carries_replicate_like_metadata(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-bench-meta-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $resolver = new TrailerPathResolver($tmp);
            $storage = new LocalArtifactStorage($resolver);

            $provider = new class implements VideoGenerationProviderInterface {
                public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult
                {
                    $targetPath = $options['target_path'];
                    if (!is_string($targetPath) || $targetPath === '') {
                        throw new \RuntimeException('expected target_path');
                    }
                    file_put_contents($targetPath, 'mp4-bytes');

                    $preset = $options['replicate_preset'] ?? '';

                    return new GeneratedAssetResult(
                        path: $targetPath,
                        duration: null,
                        metadata: [
                            'provider' => 'replicate-video',
                            'prediction_id' => 'pred-' . $preset,
                            'model' => 'bench/' . $preset,
                            'replicate_preset' => $preset,
                            'remote_output_url' => 'https://cdn.example/' . $preset . '.mp4',
                            'provider_status' => 'succeeded',
                        ],
                    );
                }
            };

            $service = new SceneVideoBenchmarkService($provider, $storage);
            $scene = new Scene(
                id: 'scene-1',
                number: 1,
                title: 'One',
                videoPrompt: 'cinematic forest',
            );
            $storage->ensureSceneDirectory('proj', $scene);

            $presets = [ReplicateVideoModelPresets::HAILUO, ReplicateVideoModelPresets::SEEDANCE];
            $ok = $service->generateVideosForPresets('proj', $scene, 'same prompt', $presets);

            self::assertTrue($ok);
            $videos = array_values(array_filter(
                $scene->assets(),
                static fn ($a) => $a->type() === AssetType::Video,
            ));
            self::assertCount(2, $videos);

            foreach ($videos as $asset) {
                $m = $asset->metadata();
                self::assertSame('replicate-video', $m['provider'] ?? null);
                self::assertArrayHasKey('prediction_id', $m);
                self::assertArrayHasKey('remote_job_id', $m);
                self::assertSame($m['prediction_id'], $m['remote_job_id']);
                self::assertArrayHasKey('provider_model', $m);
                self::assertArrayHasKey('remote_output_url', $m);
                self::assertSame($asset->path(), $m['local_path'] ?? null);
                self::assertSame($asset->path(), $m['local_artifact_path'] ?? null);
                self::assertSame('succeeded', $m['provider_state'] ?? null);
            }
        } finally {
            $this->removeTree($tmp);
        }
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
        foreach ($it as $f) {
            $p = $f->getPathname();
            $f->isDir() ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

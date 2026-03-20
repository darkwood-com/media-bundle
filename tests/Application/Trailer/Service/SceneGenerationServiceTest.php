<?php

declare(strict_types=1);

namespace App\Tests\Application\Trailer\Service;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\DTO\SceneDefinition;
use App\Application\Trailer\Port\VoiceGenerationProviderInterface;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Application\Trailer\Service\SceneGenerationService;
use App\Application\Trailer\Service\SceneVideoBenchmarkService;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Enum\SceneStatus;
use App\Domain\Trailer\Scene;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicatePredictionFailedException;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use App\Infrastructure\Trailer\Storage\TrailerPathResolver;
use PHPUnit\Framework\TestCase;

final class SceneGenerationServiceTest extends TestCase
{
    public function test_success_merges_lifecycle_metadata_on_assets(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scene-gen-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $voice = $this->createMock(VoiceGenerationProviderInterface::class);
            $video = $this->createMock(VideoGenerationProviderInterface::class);
            $unusedBenchVideo = $this->createMock(VideoGenerationProviderInterface::class);
            $unusedBenchVideo->expects(self::never())->method('generateVideo');
            $benchmark = new SceneVideoBenchmarkService($unusedBenchVideo, $storage);

            $voice->expects(self::once())->method('generateVoice')->willReturnCallback(
                function (string $text, array $options): GeneratedAssetResult {
                    $path = $options['target_path'];
                    self::assertIsString($path);
                    self::assertNotFalse(file_put_contents($path, 'a'));

                    return new GeneratedAssetResult(
                        path: $path,
                        duration: 2.0,
                        metadata: [
                            'provider' => 'replicate-voice',
                            'prediction_id' => 'pred-voice-xyz',
                            'model' => 'minimax/speech-test',
                            'remote_output_url' => 'https://cdn.example/voice.mp3',
                        ],
                    );
                }
            );

            $video->expects(self::once())->method('generateVideo')->willReturnCallback(
                function (string $prompt, array $options): GeneratedAssetResult {
                    $path = $options['target_path'];
                    self::assertIsString($path);
                    self::assertNotFalse(file_put_contents($path, 'v'));

                    return new GeneratedAssetResult(
                        path: $path,
                        duration: null,
                        metadata: [
                            'provider' => 'replicate-video',
                            'prediction_id' => 'pred-video-xyz',
                            'model' => 'bytedance/seedance-test',
                            'remote_output_url' => 'https://cdn.example/video.mp4',
                        ],
                    );
                }
            );

            $service = new SceneGenerationService($voice, $video, $storage, $benchmark);
            $scene = new Scene(
                id: 'intro',
                number: 1,
                title: 'Intro',
                videoPrompt: 'dark forest',
                narrationText: 'Hello',
            );
            $def = new SceneDefinition(
                id: 'intro',
                title: 'Intro',
                videoPrompt: 'dark forest',
                narration: 'Hello',
            );

            $service->generateScene('proj-1', $scene, $def);

            self::assertSame(SceneStatus::Completed, $scene->status());

            $voiceAsset = $this->assetByType($scene, AssetType::Voice);
            self::assertSame(AssetStatus::Completed, $voiceAsset->status());
            self::assertSame('replicate-voice', $voiceAsset->provider());
            $vm = $voiceAsset->metadata();
            self::assertSame('pred-voice-xyz', $vm['prediction_id'] ?? null);
            self::assertSame('pred-voice-xyz', $vm['remote_job_id'] ?? null);
            self::assertSame('minimax/speech-test', $vm['provider_model'] ?? null);
            self::assertSame('https://cdn.example/voice.mp3', $vm['remote_output_url'] ?? null);
            self::assertSame($voiceAsset->path(), $vm['local_path'] ?? null);
            self::assertSame($voiceAsset->path(), $vm['local_artifact_path'] ?? null);

            $videoAsset = $this->assetByType($scene, AssetType::Video);
            self::assertSame(AssetStatus::Completed, $videoAsset->status());
            self::assertSame('replicate-video', $videoAsset->provider());
            $m = $videoAsset->metadata();
            self::assertSame('pred-video-xyz', $m['prediction_id'] ?? null);
            self::assertSame('bytedance/seedance-test', $m['provider_model'] ?? null);
            self::assertSame($videoAsset->path(), $m['local_artifact_path'] ?? null);
        } finally {
            $this->removeTree($tmp);
        }
    }

    public function test_video_failure_records_replicate_fields_on_asset(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-scene-fail-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($tmp, 0o755, true));

        try {
            $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
            $voice = $this->createMock(VoiceGenerationProviderInterface::class);
            $video = $this->createMock(VideoGenerationProviderInterface::class);
            $unusedBenchVideo = $this->createMock(VideoGenerationProviderInterface::class);
            $unusedBenchVideo->expects(self::never())->method('generateVideo');
            $benchmark = new SceneVideoBenchmarkService($unusedBenchVideo, $storage);

            $voice->method('generateVoice')->willReturnCallback(
                function (string $text, array $options): GeneratedAssetResult {
                    $path = $options['target_path'];
                    file_put_contents($path, 'a');

                    return new GeneratedAssetResult($path, 1.0, ['provider' => 'fake-voice']);
                }
            );

            $video->method('generateVideo')->willThrowException(
                ReplicatePredictionFailedException::terminalPredictionFailure(
                    'pred-dead',
                    'm/model',
                    'failed',
                    'GPU sad',
                    'hailuo',
                )
            );

            $service = new SceneGenerationService($voice, $video, $storage, $benchmark);
            $scene = new Scene(
                id: 'intro',
                number: 1,
                title: 'Intro',
                videoPrompt: 'x',
                narrationText: 'y',
            );
            $def = new SceneDefinition(id: 'intro', title: 'Intro', videoPrompt: 'x', narration: 'y');

            $service->generateScene('proj-1', $scene, $def);

            self::assertSame(SceneStatus::Failed, $scene->status());
            $videoAsset = $this->assetByType($scene, AssetType::Video);
            self::assertSame(AssetStatus::Failed, $videoAsset->status());
            $meta = $videoAsset->metadata();
            self::assertSame('pred-dead', $meta['prediction_id'] ?? null);
            self::assertSame('m/model', $meta['provider_model'] ?? null);
            self::assertSame('hailuo', $meta['replicate_preset'] ?? null);
            self::assertSame('failed', $meta['remote_status'] ?? null);
            self::assertSame('GPU sad', $meta['remote_error_detail'] ?? null);
            self::assertArrayHasKey('failure_at', $meta);
        } finally {
            $this->removeTree($tmp);
        }
    }

    private function assetByType(Scene $scene, AssetType $type): \App\Domain\Trailer\Asset
    {
        foreach ($scene->assets() as $asset) {
            if ($asset->type() === $type) {
                return $asset;
            }
        }

        self::fail('No asset of type ' . $type->value);
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

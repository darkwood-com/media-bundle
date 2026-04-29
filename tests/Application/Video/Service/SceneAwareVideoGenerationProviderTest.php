<?php

declare(strict_types=1);

namespace App\Tests\Application\Video\Service;

use App\Application\Video\DTO\GeneratedAssetResult;
use App\Application\Video\Port\VideoGenerationProviderInterface;
use App\Application\Video\Service\SceneAwareVideoGenerationProvider;
use App\Infrastructure\Video\Provider\FakeVideoGenerationProvider;
use App\Infrastructure\Video\Provider\Replicate\ReplicatePredictionFailedException;
use PHPUnit\Framework\TestCase;

final class SceneAwareVideoGenerationProviderTest extends TestCase
{
    /** VIDEO_REAL_FOR_FIRST_SCENE_ONLY=1 → scene 1 real, scenes 2+ fake */
    public function test_scene_one_uses_real_when_first_scene_only_mode_and_real_is_configured(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $real = $this->createMock(VideoGenerationProviderInterface::class);

        $out = new GeneratedAssetResult(path: '/tmp/real.mp4', duration: null, metadata: ['provider' => 'real']);

        $real->expects(self::once())
            ->method('generateVideo')
            ->with('prompt', self::callback(static fn (array $o): bool => ($o['scene_number'] ?? null) === 1))
            ->willReturn($out);

        $fake->expects(self::never())->method('generateVideo');

        $router = new SceneAwareVideoGenerationProvider($fake, $real, true);
        $result = $router->generateVideo('prompt', ['scene_number' => 1, 'target_path' => '/tmp/real.mp4']);

        self::assertSame('/tmp/real.mp4', $result->path);
    }

    public function test_scene_one_string_number_still_routes_to_real_in_first_scene_only_mode(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $real = $this->createMock(VideoGenerationProviderInterface::class);

        $real->expects(self::once())->method('generateVideo')->willReturn(
            new GeneratedAssetResult(path: '/x', duration: null, metadata: []),
        );
        $fake->expects(self::never())->method('generateVideo');

        $router = new SceneAwareVideoGenerationProvider($fake, $real, true);
        $router->generateVideo('p', ['scene_number' => '1']);
    }

    /** VIDEO_REAL_FOR_FIRST_SCENE_ONLY=1 → scenes 2+ fake */
    public function test_scene_two_plus_uses_fake_when_first_scene_only_mode(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $real = $this->createMock(VideoGenerationProviderInterface::class);

        $fake->expects(self::once())
            ->method('generateVideo')
            ->with('p', self::callback(static fn (array $o): bool => ($o['scene_number'] ?? null) === 2))
            ->willReturn(new GeneratedAssetResult(path: '/f', duration: 0.0, metadata: []));

        $real->expects(self::never())->method('generateVideo');

        $router = new SceneAwareVideoGenerationProvider($fake, $real, true);
        $router->generateVideo('p', ['scene_number' => 2, 'target_path' => '/f']);
    }

    /** VIDEO_REAL_FOR_FIRST_SCENE_ONLY=0 → all scenes real */
    public function test_scene_two_uses_real_when_all_scenes_real_flag(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $real = $this->createMock(VideoGenerationProviderInterface::class);

        $real->expects(self::once())
            ->method('generateVideo')
            ->with('p', self::callback(static fn (array $o): bool => ($o['scene_number'] ?? null) === 2))
            ->willReturn(new GeneratedAssetResult(path: '/r2', duration: 0.0, metadata: []));

        $fake->expects(self::never())->method('generateVideo');

        $router = new SceneAwareVideoGenerationProvider($fake, $real, false);
        $router->generateVideo('p', ['scene_number' => 2, 'target_path' => '/r2']);
    }

    public function test_when_real_unconfigured_scene_one_uses_fake(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $fake->expects(self::once())->method('generateVideo')->willReturn(
            new GeneratedAssetResult(path: '/f', duration: 0.0, metadata: []),
        );

        $router = new SceneAwareVideoGenerationProvider($fake, null, false);
        $router->generateVideo('p', ['scene_number' => 1]);
    }

    public function test_real_failure_falls_back_to_fake_with_metadata_hint(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $real = $this->createMock(VideoGenerationProviderInterface::class);

        $real->expects(self::once())->method('generateVideo')->willThrowException(new \RuntimeException('api down'));

        $fake->expects(self::once())
            ->method('generateVideo')
            ->with('p', self::callback(static fn (array $o): bool => ($o['fallback_from'] ?? null) === 'real'))
            ->willReturn(new GeneratedAssetResult(path: '/fallback.mp4', duration: 0.0, metadata: []));

        $router = new SceneAwareVideoGenerationProvider($fake, $real, true);
        $router->generateVideo('p', ['scene_number' => 1, 'target_path' => '/fallback.mp4']);
    }

    public function test_real_failure_on_scene_two_falls_back_when_all_scenes_real(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $real = $this->createMock(VideoGenerationProviderInterface::class);

        $real->expects(self::once())->method('generateVideo')->willThrowException(new \RuntimeException('timeout'));

        $fake->expects(self::once())
            ->method('generateVideo')
            ->with('p', self::callback(static fn (array $o): bool => ($o['fallback_from'] ?? null) === 'real'
                && ($o['scene_number'] ?? null) === 2))
            ->willReturn(new GeneratedAssetResult(path: '/fb2.mp4', duration: 0.0, metadata: []));

        $router = new SceneAwareVideoGenerationProvider($fake, $real, false);
        $router->generateVideo('p', ['scene_number' => 2, 'target_path' => '/fb2.mp4']);
    }

    public function test_fallback_records_real_attempt_prediction_and_model(): void
    {
        $fake = new FakeVideoGenerationProvider();
        $real = $this->createMock(VideoGenerationProviderInterface::class);
        $real->expects(self::once())->method('generateVideo')->willThrowException(
            ReplicatePredictionFailedException::terminalPredictionFailure(
                'pred-fallback',
                'vendor/model-x',
                'failed',
                'rate limit',
                'hailuo',
            )
        );

        $targetPath = sys_get_temp_dir() . '/dw-fb-video-' . uniqid('', true) . '.mp4';
        $router = new SceneAwareVideoGenerationProvider($fake, $real, true);
        $result = $router->generateVideo('prompt', ['scene_number' => 1, 'target_path' => $targetPath]);

        try {
            self::assertSame('fake-video', $result->metadata['provider'] ?? null);
            self::assertSame('real', $result->metadata['fallback_from'] ?? null);
            self::assertSame('pred-fallback', $result->metadata['real_attempt_prediction_id'] ?? null);
            self::assertSame('vendor/model-x', $result->metadata['real_attempt_provider_model'] ?? null);
            self::assertSame('failed', $result->metadata['real_attempt_remote_status'] ?? null);
            self::assertStringContainsString('rate limit', (string) ($result->metadata['real_attempt_error_message'] ?? ''));
            self::assertSame('hailuo', $result->metadata['real_attempt_replicate_preset'] ?? null);
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Application\Trailer\Service;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Application\Trailer\Service\SceneAwareVideoGenerationProvider;
use PHPUnit\Framework\TestCase;

final class SceneAwareVideoGenerationProviderTest extends TestCase
{
    public function test_scene_one_uses_real_when_enabled_and_real_is_configured(): void
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

    public function test_scene_one_string_number_still_routes_to_real(): void
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

    public function test_scene_two_plus_uses_fake(): void
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

    public function test_when_real_unconfigured_scene_one_uses_fake(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $fake->expects(self::once())->method('generateVideo')->willReturn(
            new GeneratedAssetResult(path: '/f', duration: 0.0, metadata: []),
        );

        $router = new SceneAwareVideoGenerationProvider($fake, null, true);
        $router->generateVideo('p', ['scene_number' => 1]);
    }

    public function test_when_real_disabled_flag_all_scenes_use_fake(): void
    {
        $fake = $this->createMock(VideoGenerationProviderInterface::class);
        $real = $this->createMock(VideoGenerationProviderInterface::class);

        $fake->expects(self::once())->method('generateVideo')->willReturn(
            new GeneratedAssetResult(path: '/f', duration: 0.0, metadata: []),
        );
        $real->expects(self::never())->method('generateVideo');

        $router = new SceneAwareVideoGenerationProvider($fake, $real, false);
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
}

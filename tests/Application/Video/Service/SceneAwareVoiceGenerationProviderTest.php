<?php

declare(strict_types=1);

namespace App\Tests\Application\Video\Service;

use App\Application\Video\DTO\GeneratedAssetResult;
use App\Application\Video\Port\VoiceGenerationProviderInterface;
use App\Application\Video\Service\SceneAwareVoiceGenerationProvider;
use App\Infrastructure\Video\Provider\FakeVoiceGenerationProvider;
use App\Infrastructure\Video\Provider\Replicate\ReplicatePredictionFailedException;
use PHPUnit\Framework\TestCase;

final class SceneAwareVoiceGenerationProviderTest extends TestCase
{
    public function test_scene_one_uses_real_when_first_scene_only_mode_and_real_is_configured(): void
    {
        $fake = $this->createMock(VoiceGenerationProviderInterface::class);
        $real = $this->createMock(VoiceGenerationProviderInterface::class);

        $out = new GeneratedAssetResult(path: '/tmp/real.mp3', duration: null, metadata: ['provider' => 'real']);

        $real->expects(self::once())
            ->method('generateVoice')
            ->with('Hello', self::callback(static fn (array $o): bool => ($o['scene_number'] ?? null) === 1))
            ->willReturn($out);

        $fake->expects(self::never())->method('generateVoice');

        $router = new SceneAwareVoiceGenerationProvider($fake, $real, true);
        $result = $router->generateVoice('Hello', ['scene_number' => 1, 'target_path' => '/tmp/real.mp3']);

        self::assertSame('/tmp/real.mp3', $result->path);
    }

    public function test_scene_one_string_number_still_routes_to_real_in_first_scene_only_mode(): void
    {
        $fake = $this->createMock(VoiceGenerationProviderInterface::class);
        $real = $this->createMock(VoiceGenerationProviderInterface::class);

        $real->expects(self::once())->method('generateVoice')->willReturn(
            new GeneratedAssetResult(path: '/x.mp3', duration: null, metadata: []),
        );
        $fake->expects(self::never())->method('generateVoice');

        $router = new SceneAwareVoiceGenerationProvider($fake, $real, true);
        $router->generateVoice('Hi', ['scene_number' => '1']);
    }

    public function test_scene_two_plus_uses_fake_when_first_scene_only_mode(): void
    {
        $fake = $this->createMock(VoiceGenerationProviderInterface::class);
        $real = $this->createMock(VoiceGenerationProviderInterface::class);

        $fake->expects(self::once())
            ->method('generateVoice')
            ->with('Hi', self::callback(static fn (array $o): bool => ($o['scene_number'] ?? null) === 2))
            ->willReturn(new GeneratedAssetResult(path: '/f.mp3', duration: 0.0, metadata: []));

        $real->expects(self::never())->method('generateVoice');

        $router = new SceneAwareVoiceGenerationProvider($fake, $real, true);
        $router->generateVoice('Hi', ['scene_number' => 2, 'target_path' => '/f.mp3']);
    }

    public function test_scene_two_uses_real_when_all_scenes_real_flag(): void
    {
        $fake = $this->createMock(VoiceGenerationProviderInterface::class);
        $real = $this->createMock(VoiceGenerationProviderInterface::class);

        $real->expects(self::once())
            ->method('generateVoice')
            ->with('Hi', self::callback(static fn (array $o): bool => ($o['scene_number'] ?? null) === 2))
            ->willReturn(new GeneratedAssetResult(path: '/r2.mp3', duration: 0.0, metadata: []));

        $fake->expects(self::never())->method('generateVoice');

        $router = new SceneAwareVoiceGenerationProvider($fake, $real, false);
        $router->generateVoice('Hi', ['scene_number' => 2, 'target_path' => '/r2.mp3']);
    }

    public function test_when_real_unconfigured_scene_one_uses_fake(): void
    {
        $fake = $this->createMock(VoiceGenerationProviderInterface::class);
        $fake->expects(self::once())->method('generateVoice')->willReturn(
            new GeneratedAssetResult(path: '/f.mp3', duration: 0.0, metadata: []),
        );

        $router = new SceneAwareVoiceGenerationProvider($fake, null, false);
        $router->generateVoice('Hi', ['scene_number' => 1]);
    }

    public function test_real_failure_falls_back_to_fake_with_metadata_hint(): void
    {
        $fake = $this->createMock(VoiceGenerationProviderInterface::class);
        $real = $this->createMock(VoiceGenerationProviderInterface::class);

        $real->expects(self::once())->method('generateVoice')->willThrowException(new \RuntimeException('api down'));

        $fake->expects(self::once())
            ->method('generateVoice')
            ->with('Hi', self::callback(static fn (array $o): bool => ($o['fallback_from'] ?? null) === 'real'))
            ->willReturn(new GeneratedAssetResult(path: '/fallback.mp3', duration: 0.0, metadata: []));

        $router = new SceneAwareVoiceGenerationProvider($fake, $real, true);
        $router->generateVoice('Hi', ['scene_number' => 1, 'target_path' => '/fallback.mp3']);
    }

    public function test_real_failure_on_scene_two_falls_back_when_all_scenes_real(): void
    {
        $fake = $this->createMock(VoiceGenerationProviderInterface::class);
        $real = $this->createMock(VoiceGenerationProviderInterface::class);

        $real->expects(self::once())->method('generateVoice')->willThrowException(new \RuntimeException('timeout'));

        $fake->expects(self::once())
            ->method('generateVoice')
            ->with('Hi', self::callback(static fn (array $o): bool => ($o['fallback_from'] ?? null) === 'real'
                && ($o['scene_number'] ?? null) === 2))
            ->willReturn(new GeneratedAssetResult(path: '/fb2.mp3', duration: 0.0, metadata: []));

        $router = new SceneAwareVoiceGenerationProvider($fake, $real, false);
        $router->generateVoice('Hi', ['scene_number' => 2, 'target_path' => '/fb2.mp3']);
    }

    public function test_fallback_records_replicate_failure_on_voice_metadata(): void
    {
        $fake = new FakeVoiceGenerationProvider();
        $real = $this->createMock(VoiceGenerationProviderInterface::class);
        $real->expects(self::once())->method('generateVoice')->willThrowException(
            ReplicatePredictionFailedException::terminalPredictionFailure(
                'pred-v-fail',
                'minimax/speech-x',
                'failed',
                'bad text',
                null,
            )
        );

        $targetPath = sys_get_temp_dir() . '/dw-fb-voice-' . uniqid('', true) . '.mp3';
        $router = new SceneAwareVoiceGenerationProvider($fake, $real, true);
        $result = $router->generateVoice('Hi', ['scene_number' => 1, 'target_path' => $targetPath]);

        try {
            self::assertSame('fake-voice', $result->metadata['provider'] ?? null);
            self::assertSame('real', $result->metadata['fallback_from'] ?? null);
            self::assertSame('pred-v-fail', $result->metadata['real_attempt_prediction_id'] ?? null);
            self::assertSame('minimax/speech-x', $result->metadata['real_attempt_provider_model'] ?? null);
            self::assertSame('failed', $result->metadata['real_attempt_remote_status'] ?? null);
            self::assertStringContainsString('bad text', (string) ($result->metadata['real_attempt_error_message'] ?? ''));
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }
}

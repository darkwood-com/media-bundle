<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VoiceGenerationProviderInterface;

/**
 * When $useRealForFirstSceneOnly is true and a real provider is wired, scene 1 uses Replicate TTS; other scenes use fake audio.
 * Toggle: parameter trailer.voice.real_for_first_scene_only (config/services.yaml).
 */
final class SceneAwareVoiceGenerationProvider implements VoiceGenerationProviderInterface
{
    public function __construct(
        private readonly VoiceGenerationProviderInterface $fakeProvider,
        private readonly ?VoiceGenerationProviderInterface $realProvider = null,
        private readonly bool $useRealForFirstSceneOnly = false,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateVoice(string $text, array $options = []): GeneratedAssetResult
    {
        $provider = $this->selectProvider($options);

        try {
            return $provider->generateVoice($text, $options);
        } catch (\Throwable $e) {
            if ($provider === $this->realProvider && $this->fakeProvider !== $this->realProvider) {
                $options['fallback_from'] = 'real';

                return $this->fakeProvider->generateVoice($text, $options);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function selectProvider(array $options): VoiceGenerationProviderInterface
    {
        if ($this->useRealForFirstSceneOnly && $this->realProvider !== null) {
            $sceneNumber = $options['scene_number'] ?? null;

            if ($sceneNumber === 1 || $sceneNumber === '1') {
                return $this->realProvider;
            }
        }

        return $this->fakeProvider;
    }
}

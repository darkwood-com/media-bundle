<?php

declare(strict_types=1);

namespace App\Application\Video\Service;

use App\Application\Video\DTO\GeneratedAssetResult;
use App\Application\Video\Port\VoiceGenerationProviderInterface;
use App\Infrastructure\Video\Provider\Replicate\ReplicatePredictionFailedException;

/**
 * Same routing as video: VIDEO_REAL_FOR_FIRST_SCENE_ONLY=1 → only scene 1 real when wired;
 * =0 → all scenes real. Real failures fall back to fake with metadata.
 *
 * Parameter: video.real_for_first_scene_only.
 */
final class SceneAwareVoiceGenerationProvider implements VoiceGenerationProviderInterface
{
    public function __construct(
        private readonly VoiceGenerationProviderInterface $fakeProvider,
        private readonly ?VoiceGenerationProviderInterface $realProvider = null,
        private readonly bool $realForFirstSceneOnly = false,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateVoice(string $text, array $options = []): GeneratedAssetResult
    {
        $sceneNumber = $this->sceneNumber($options);
        $provider = $this->selectProvider($sceneNumber);

        try {
            return $provider->generateVoice($text, $options);
        } catch (\Throwable $e) {
            if ($provider === $this->realProvider && $this->fakeProvider !== $this->realProvider) {
                $options['fallback_from'] = 'real';
                $options['real_attempt_error_message'] = $e->getMessage();
                if ($e instanceof ReplicatePredictionFailedException) {
                    $options['real_attempt_prediction_id'] = $e->predictionId();
                    $options['real_attempt_provider_model'] = $e->model();
                    $options['real_attempt_remote_status'] = $e->remoteStatus();
                }

                return $this->fakeProvider->generateVoice($text, $options);
            }

            throw $e;
        }
    }

    /**
     * VIDEO_REAL_FOR_FIRST_SCENE_ONLY semantics:
     * - true  => only scene 1 uses real; scenes 2+ use fake
     * - false => all scenes use real
     */
    private function selectProvider(?int $sceneNumber): VoiceGenerationProviderInterface
    {
        if ($this->realProvider === null) {
            return $this->fakeProvider;
        }

        if ($this->realForFirstSceneOnly) {
            if ($sceneNumber === 1) {
                return $this->realProvider;
            }

            return $this->fakeProvider;
        }

        return $this->realProvider;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function sceneNumber(array $options): ?int
    {
        $sceneNumber = $options['scene_number'] ?? null;

        if (is_int($sceneNumber)) {
            return $sceneNumber;
        }

        if (is_string($sceneNumber) && ctype_digit($sceneNumber)) {
            return (int) $sceneNumber;
        }

        return null;
    }
}

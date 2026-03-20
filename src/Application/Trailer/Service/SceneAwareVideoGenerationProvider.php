<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicatePredictionFailedException;

/**
 * Routes to Replicate when wired: TRAILER_REAL_FOR_FIRST_SCENE_ONLY=1 means only scene 1 uses
 * the real provider; =0 means every scene does. On real failure, falls back to fake and enriches
 * options/metadata (see generateVideo).
 *
 * Toggle parameter id: trailer.real_for_first_scene_only (same env var name).
 *
 * The CLI `app:trailer:generate` path uses this service; scene-1 benchmark presets call
 * ReplicateVideoGenerationProvider directly (SceneVideoBenchmarkService).
 */
final class SceneAwareVideoGenerationProvider implements VideoGenerationProviderInterface
{
    public function __construct(
        private readonly VideoGenerationProviderInterface $fakeProvider,
        private readonly ?VideoGenerationProviderInterface $realProvider = null,
        private readonly bool $realForFirstSceneOnly = false,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult
    {
        $sceneNumber = $this->sceneNumber($options);
        $provider = $this->selectProvider($sceneNumber);

        try {
            return $provider->generateVideo($prompt, $options);
        } catch (\Throwable $e) {
            if ($provider === $this->realProvider && $this->fakeProvider !== $this->realProvider) {
                $options['fallback_from'] = 'real';
                $options['real_attempt_error_message'] = $e->getMessage();
                if ($e instanceof ReplicatePredictionFailedException) {
                    $options['real_attempt_prediction_id'] = $e->predictionId();
                    $options['real_attempt_provider_model'] = $e->model();
                    $options['real_attempt_remote_status'] = $e->remoteStatus();
                    $preset = $e->replicatePreset();
                    if ($preset !== null && $preset !== '') {
                        $options['real_attempt_replicate_preset'] = $preset;
                    }
                }

                return $this->fakeProvider->generateVideo($prompt, $options);
            }

            throw $e;
        }
    }

    /**
     * TRAILER_REAL_FOR_FIRST_SCENE_ONLY semantics:
     * - true  => only scene 1 uses real; scenes 2+ use fake
     * - false => all scenes use real
     */
    private function selectProvider(?int $sceneNumber): VideoGenerationProviderInterface
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

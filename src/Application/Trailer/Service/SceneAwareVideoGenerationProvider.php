<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;

/**
 * Default video provider for trailer generation: fake for every scene except scene 1 when
 * $useRealForFirstSceneOnly is true and $realProvider is non-null (Replicate).
 *
 * The CLI `app:trailer:generate` path uses this service; scene-1 benchmark presets call
 * \App\Infrastructure\Trailer\Provider\ReplicateVideoGenerationProvider directly
 * (\App\Application\Trailer\Service\SceneVideoBenchmarkService).
 */
final class SceneAwareVideoGenerationProvider implements VideoGenerationProviderInterface
{
    public function __construct(
        private readonly VideoGenerationProviderInterface $fakeProvider,
        private readonly ?VideoGenerationProviderInterface $realProvider = null,
        private readonly bool $useRealForFirstSceneOnly = false,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult
    {
        $provider = $this->selectProvider($options);

        try {
            return $provider->generateVideo($prompt, $options);
        } catch (\Throwable $e) {
            if ($provider === $this->realProvider && $this->fakeProvider !== $this->realProvider) {
                $options['fallback_from'] = 'real';

                return $this->fakeProvider->generateVideo($prompt, $options);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function selectProvider(array $options): VideoGenerationProviderInterface
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


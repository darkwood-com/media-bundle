<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\Port\ArtifactStorageInterface;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Domain\Trailer\Asset;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Scene;

/**
 * Scene 1 video-only benchmark: same prompt, multiple Replicate presets, distinct artifact files.
 *
 * The Symfony container injects ReplicateVideoGenerationProvider (not SceneAwareVideoGenerationProvider) so
 * API failures are not masked by the router’s fake fallback.
 *
 * Voice is intentionally not generated here; pair with {@see SceneGenerationService::generateSceneWithVideoBenchmarkPresets}
 * which marks the voice asset skipped for benchmark runs.
 */
final class SceneVideoBenchmarkService
{
    public function __construct(
        private readonly VideoGenerationProviderInterface $videoProvider,
        private readonly ArtifactStorageInterface $artifactStorage,
    ) {
    }

    /**
     * @param list<string>         $presetKeys
     * @param array<string, mixed> $baseVideoOptions merged before each replicate_preset
     *
     * @return bool false if any video generation failed (scene already marked failed)
     */
    public function generateVideosForPresets(
        string $projectId,
        Scene $scene,
        string $videoPrompt,
        array $presetKeys,
        array $baseVideoOptions = [],
    ): bool {
        foreach ($presetKeys as $presetKey) {
            $opts = array_merge($baseVideoOptions, ['replicate_preset' => $presetKey]);
            $videoPath = $this->artifactStorage->getSceneVideoOutputPath($projectId, $scene, $opts);
            $videoAsset = $this->findOrCreateVideoAsset($scene, $opts);
            if (!$this->generateVideo($scene, $videoAsset, $videoPrompt, $videoPath, $opts)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $videoProviderOptions
     */
    private function findOrCreateVideoAsset(Scene $scene, array $videoProviderOptions): Asset
    {
        $suffix = $this->artifactStorage->resolveSceneVideoArtifactSuffix($videoProviderOptions);
        if ($suffix === null) {
            return $this->findOrCreateCanonicalVideoAsset($scene);
        }

        $id = $scene->id() . '-video--' . $suffix;
        foreach ($scene->assets() as $asset) {
            if ($asset->id() === $id) {
                return $asset;
            }
        }

        $asset = new Asset(
            id: $id,
            sceneId: $scene->id(),
            type: AssetType::Video,
            status: AssetStatus::Pending,
        );
        $scene->addAsset($asset);

        return $asset;
    }

    private function findOrCreateCanonicalVideoAsset(Scene $scene): Asset
    {
        $canonicalId = $scene->id() . '-' . AssetType::Video->value;
        foreach ($scene->assets() as $asset) {
            if ($asset->id() === $canonicalId) {
                return $asset;
            }
        }

        $asset = new Asset(
            id: $canonicalId,
            sceneId: $scene->id(),
            type: AssetType::Video,
            status: AssetStatus::Pending,
        );
        $scene->addAsset($asset);

        return $asset;
    }

    /**
     * @param array<string, mixed> $extraOptions
     */
    private function generateVideo(Scene $scene, Asset $asset, string $prompt, string $targetPath, array $extraOptions = []): bool
    {
        $asset->markProcessing(null);

        try {
            $result = $this->videoProvider->generateVideo($prompt, array_merge([
                'target_path' => $targetPath,
                'scene_id' => $scene->id(),
                'scene_number' => $scene->number(),
            ], $extraOptions));
            $metadata = $this->normalizeAssetMetadata($result->metadata, $result->path, $extraOptions);
            $asset->complete($result->path, $metadata);
            if ($result->duration !== null && $scene->duration() === null) {
                $scene->setDuration($result->duration);
            }

            return true;
        } catch (\Throwable $e) {
            $message = 'Video generation failed: ' . $e->getMessage();
            $asset->updateMetadata([
                'provider_state' => 'error',
                'provider_error_message' => $e->getMessage(),
            ]);
            $asset->fail($message);
            $scene->fail($message);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $videoProviderOptions
     *
     * @return array<string, mixed>
     */
    private function normalizeAssetMetadata(array $metadata, string $localPath, array $videoProviderOptions = []): array
    {
        $normalized = $metadata;
        $normalized['local_path'] = $localPath;

        if (isset($metadata['prediction_id']) && !isset($normalized['remote_job_id'])) {
            $normalized['remote_job_id'] = $metadata['prediction_id'];
        }

        if (isset($metadata['provider_status']) && !isset($normalized['provider_state'])) {
            $normalized['provider_state'] = $metadata['provider_status'];
        }

        if (isset($metadata['remote_output_url']) && !isset($normalized['remote_output_url'])) {
            $normalized['remote_output_url'] = $metadata['remote_output_url'];
        }

        $suffix = $this->artifactStorage->resolveSceneVideoArtifactSuffix($videoProviderOptions);
        if ($suffix !== null) {
            $normalized['video_artifact_suffix'] = $suffix;
            $normalized['video_artifact_file'] = basename($localPath);
        }

        return $normalized;
    }
}

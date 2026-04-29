<?php

declare(strict_types=1);

namespace App\Application\Video\Service;

use App\Application\Video\DTO\SceneDefinition;
use App\Application\Video\Port\ArtifactStorageInterface;
use App\Application\Video\Port\VoiceGenerationProviderInterface;
use App\Application\Video\Port\VideoGenerationProviderInterface;
use App\Domain\Video\Asset;
use App\Domain\Video\Enum\AssetStatus;
use App\Domain\Video\Enum\AssetType;
use App\Domain\Video\Scene;
use Spatie\Fork\Fork;

/**
 * Generates all assets (voice, video) for a single scene.
 * Reused by full-project generation and scene reruns.
 */
final class SceneGenerationService
{
    public function __construct(
        private readonly VoiceGenerationProviderInterface $voiceProvider,
        private readonly VideoGenerationProviderInterface $videoProvider,
        private readonly ArtifactStorageInterface $artifactStorage,
        private readonly SceneVideoBenchmarkService $sceneVideoBenchmarkService,
    ) {
    }

    /**
     * Generate voice and video assets for the scene end-to-end.
     * Updates scene and asset status in place; on failure marks scene failed and preserves error message.
     *
     * @param array<string, mixed> $videoProviderOptions Merged into the video provider call (e.g. replicate_preset)
     */
    public function generateScene(string $projectId, Scene $scene, SceneDefinition $definition, array $videoProviderOptions = []): void
    {
        $scene->markProcessing();
        $this->artifactStorage->ensureSceneDirectory($projectId, $scene);

        $voicePath = $this->artifactStorage->getSceneVoiceOutputPath($projectId, $scene);
        $videoPath = $this->artifactStorage->getSceneVideoOutputPath($projectId, $scene, $videoProviderOptions);

        $voiceAsset = $this->findOrCreateAsset($scene, AssetType::Voice);
        $videoAsset = $this->findOrCreateVideoAsset($scene, $videoProviderOptions);

        if ($this->shouldParallelizeVoiceAndVideo($definition)) {
            $voiceAsset->markProcessing(null);
            $videoAsset->markProcessing(null);
            [$voiceOut, $videoOut] = Fork::new()->run(
                fn () => $this->forkExecuteVoice($definition->narration, $voicePath, $scene->id(), $scene->number()),
                fn () => $this->forkExecuteVideo(
                    $definition->videoPrompt,
                    $videoPath,
                    $scene->id(),
                    $scene->number(),
                    $videoProviderOptions,
                ),
            );
            $this->applyVoiceForkOutcome($scene, $voiceAsset, $voiceOut);
            $this->applyVideoForkOutcome($scene, $videoAsset, $videoOut, $videoProviderOptions);
            $this->finalizeSceneAfterParallelAssets($scene, $voiceOut, $videoOut);

            return;
        }

        if ($definition->narration !== '') {
            if (!$this->generateVoice($scene, $voiceAsset, $definition->narration, $voicePath)) {
                return;
            }
        } else {
            $voiceAsset->complete($voicePath, ['skipped' => true, 'reason' => 'empty narration']);
        }

        if ($definition->videoPrompt !== '') {
            if (!$this->generateVideo($scene, $videoAsset, $definition->videoPrompt, $videoPath, $videoProviderOptions)) {
                return;
            }
        } else {
            $videoAsset->complete($videoPath, ['skipped' => true, 'reason' => 'empty prompt']);
        }

        $scene->complete();
    }

    /**
     * Scene 1 benchmark: voice skipped (no TTS); one video file + video asset per preset, same prompt.
     *
     * @param list<string>           $presetKeys
     * @param array<string, mixed>   $baseVideoOptions merged before each replicate_preset (e.g. replicate_model)
     */
    public function generateSceneWithVideoBenchmarkPresets(
        string $projectId,
        Scene $scene,
        SceneDefinition $definition,
        array $presetKeys,
        array $baseVideoOptions = [],
    ): void {
        $scene->markProcessing();
        $this->artifactStorage->ensureSceneDirectory($projectId, $scene);

        $voicePath = $this->artifactStorage->getSceneVoiceOutputPath($projectId, $scene);
        $voiceAsset = $this->findOrCreateAsset($scene, AssetType::Voice);
        $voiceAsset->complete($voicePath, ['skipped' => true, 'reason' => 'video_benchmark_mode']);

        if ($definition->videoPrompt === '') {
            $videoPath = $this->artifactStorage->getSceneVideoOutputPath($projectId, $scene, []);
            $videoAsset = $this->findOrCreateVideoAsset($scene, []);
            $videoAsset->complete($videoPath, ['skipped' => true, 'reason' => 'empty prompt']);
            $scene->complete();

            return;
        }

        if ($this->sceneVideoBenchmarkService->generateVideosForPresets(
            $projectId,
            $scene,
            $definition->videoPrompt,
            $presetKeys,
            $baseVideoOptions,
        )) {
            $scene->complete();
        }
    }

    private function shouldParallelizeVoiceAndVideo(SceneDefinition $definition): bool
    {
        if ($definition->narration === '' || $definition->videoPrompt === '') {
            return false;
        }

        return $this->isForkParallelEnabled();
    }

    private function isForkParallelEnabled(): bool
    {
        if (!\function_exists('pcntl_fork')) {
            return false;
        }
        if (!class_exists(Fork::class)) {
            return false;
        }

        $v = $_ENV['VIDEO_PARALLEL_FORK'] ?? getenv('VIDEO_PARALLEL_FORK');
        if ($v === false || $v === '') {
            return true;
        }

        return $v !== '0';
    }

    /**
     * @return array{ok: true, path: string, duration: ?float, metadata: array<string, mixed>}|array{ok: false, error: string, metadata: array<string, mixed>}
     */
    private function forkExecuteVoice(string $narration, string $targetPath, string $sceneId, int $sceneNumber): array
    {
        try {
            $result = $this->voiceProvider->generateVoice($narration, [
                'target_path' => $targetPath,
                'scene_id' => $sceneId,
                'scene_number' => $sceneNumber,
            ]);

            return [
                'ok' => true,
                'path' => $result->path,
                'duration' => $result->duration,
                'metadata' => $result->metadata,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Voice generation failed: ' . $e->getMessage(),
                'metadata' => ProviderFailureMetadata::forThrowable($e),
            ];
        }
    }

    /**
     * @param array<string, mixed> $extraOptions
     *
     * @return array{ok: true, path: string, duration: ?float, metadata: array<string, mixed>}|array{ok: false, error: string, metadata: array<string, mixed>}
     */
    private function forkExecuteVideo(
        string $prompt,
        string $targetPath,
        string $sceneId,
        int $sceneNumber,
        array $extraOptions,
    ): array {
        try {
            $result = $this->videoProvider->generateVideo($prompt, array_merge([
                'target_path' => $targetPath,
                'scene_id' => $sceneId,
                'scene_number' => $sceneNumber,
            ], $extraOptions));

            return [
                'ok' => true,
                'path' => $result->path,
                'duration' => $result->duration,
                'metadata' => $result->metadata,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Video generation failed: ' . $e->getMessage(),
                'metadata' => ProviderFailureMetadata::forThrowable($e),
            ];
        }
    }

    /**
     * @param array{ok: true, path: string, duration: ?float, metadata: array<string, mixed>}|array{ok: false, error: string, metadata: array<string, mixed>} $out
     */
    private function applyVoiceForkOutcome(Scene $scene, Asset $asset, array $out): void
    {
        if ($out['ok']) {
            $metadata = $this->normalizeAssetMetadata($out['metadata'], $out['path']);
            $asset->complete($out['path'], $metadata);
            if ($out['duration'] !== null) {
                $scene->setDuration($out['duration']);
            }

            return;
        }

        $asset->updateMetadata($out['metadata']);
        $asset->fail($out['error']);
    }

    /**
     * @param array{ok: true, path: string, duration: ?float, metadata: array<string, mixed>}|array{ok: false, error: string, metadata: array<string, mixed>} $out
     * @param array<string, mixed> $videoProviderOptions
     */
    private function applyVideoForkOutcome(Scene $scene, Asset $asset, array $out, array $videoProviderOptions = []): void
    {
        if ($out['ok']) {
            $metadata = $this->normalizeAssetMetadata($out['metadata'], $out['path'], $videoProviderOptions);
            $asset->complete($out['path'], $metadata);
            if ($out['duration'] !== null && $scene->duration() === null) {
                $scene->setDuration($out['duration']);
            }

            return;
        }

        $asset->updateMetadata($out['metadata']);
        $asset->fail($out['error']);
    }

    /**
     * @param array{ok: true, path: string, duration: ?float, metadata: array<string, mixed>}|array{ok: false, error: string, metadata: array<string, mixed>} $voiceOut
     * @param array{ok: true, path: string, duration: ?float, metadata: array<string, mixed>}|array{ok: false, error: string, metadata: array<string, mixed>} $videoOut
     */
    private function finalizeSceneAfterParallelAssets(Scene $scene, array $voiceOut, array $videoOut): void
    {
        $voiceOk = $voiceOut['ok'];
        $videoOk = $videoOut['ok'];
        if (!$voiceOk && !$videoOk) {
            $scene->fail($voiceOut['error'] . ' ' . $videoOut['error']);

            return;
        }
        if (!$voiceOk) {
            $scene->fail($voiceOut['error']);

            return;
        }
        if (!$videoOk) {
            $scene->fail($videoOut['error']);

            return;
        }
        $scene->complete();
    }

    private function findOrCreateAsset(Scene $scene, AssetType $type): Asset
    {
        foreach ($scene->assets() as $asset) {
            if ($asset->type() === $type) {
                return $asset;
            }
        }

        $id = $scene->id() . '-' . $type->value;
        $asset = new Asset(
            id: $id,
            sceneId: $scene->id(),
            type: $type,
            status: AssetStatus::Pending,
        );
        $scene->addAsset($asset);

        return $asset;
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

    /**
     * Default scene video slot ({sceneId}-video), distinct from benchmark assets ({sceneId}-video--suffix).
     */
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

    private function generateVoice(Scene $scene, Asset $asset, string $narration, string $targetPath): bool
    {
        $asset->markProcessing(null);

        try {
            $result = $this->voiceProvider->generateVoice($narration, [
                'target_path' => $targetPath,
                'scene_id' => $scene->id(),
                'scene_number' => $scene->number(),
            ]);
            $metadata = $this->normalizeAssetMetadata($result->metadata, $result->path);
            $asset->complete($result->path, $metadata);
            if ($result->duration !== null) {
                $scene->setDuration($result->duration);
            }

            return true;
        } catch (\Throwable $e) {
            $message = 'Voice generation failed: ' . $e->getMessage();
            $asset->updateMetadata(ProviderFailureMetadata::forThrowable($e));
            $asset->fail($message);
            $scene->fail($message);

            return false;
        }
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
            $asset->updateMetadata(ProviderFailureMetadata::forThrowable($e));
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
        $normalized['local_artifact_path'] = $localPath;

        if (isset($metadata['prediction_id']) && !isset($normalized['remote_job_id'])) {
            $normalized['remote_job_id'] = $metadata['prediction_id'];
        }

        if (isset($metadata['provider_status']) && !isset($normalized['provider_state'])) {
            $normalized['provider_state'] = $metadata['provider_status'];
        }

        if (isset($metadata['model']) && is_string($metadata['model']) && $metadata['model'] !== ''
            && !isset($normalized['provider_model'])) {
            $normalized['provider_model'] = $metadata['model'];
        }

        $suffix = $this->artifactStorage->resolveSceneVideoArtifactSuffix($videoProviderOptions);
        if ($suffix !== null) {
            $normalized['video_artifact_suffix'] = $suffix;
            $normalized['video_artifact_file'] = basename($localPath);
        }

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\SceneDefinition;
use App\Application\Trailer\Port\ArtifactStorageInterface;
use App\Application\Trailer\Port\VoiceGenerationProviderInterface;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Domain\Trailer\Asset;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Scene;

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
    ) {
    }

    /**
     * Generate voice and video assets for the scene end-to-end.
     * Updates scene and asset status in place; on failure marks scene failed and preserves error message.
     */
    public function generateScene(string $projectId, Scene $scene, SceneDefinition $definition): void
    {
        $scene->markProcessing();
        $this->artifactStorage->ensureSceneDirectory($projectId, $scene);

        $voicePath = $this->artifactStorage->getSceneVoiceOutputPath($projectId, $scene);
        $videoPath = $this->artifactStorage->getSceneVideoOutputPath($projectId, $scene);

        $voiceAsset = $this->findOrCreateAsset($scene, AssetType::Voice);
        $videoAsset = $this->findOrCreateAsset($scene, AssetType::Video);

        if ($definition->narration !== '') {
            if (!$this->generateVoice($scene, $voiceAsset, $definition->narration, $voicePath)) {
                return;
            }
        } else {
            $voiceAsset->complete($voicePath, ['skipped' => true, 'reason' => 'empty narration']);
        }

        if ($definition->videoPrompt !== '') {
            if (!$this->generateVideo($scene, $videoAsset, $definition->videoPrompt, $videoPath)) {
                return;
            }
        } else {
            $videoAsset->complete($videoPath, ['skipped' => true, 'reason' => 'empty prompt']);
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

    private function generateVoice(Scene $scene, Asset $asset, string $narration, string $targetPath): bool
    {
        $asset->markProcessing(null);

        try {
            $result = $this->voiceProvider->generateVoice($narration, [
                'target_path' => $targetPath,
                'scene_id' => $scene->id(),
            ]);
            $asset->complete($result->path, $result->metadata);
            if ($result->duration !== null) {
                $scene->setDuration($result->duration);
            }
            return true;
        } catch (\Throwable $e) {
            $message = 'Voice generation failed: ' . $e->getMessage();
            $asset->fail($message);
            $scene->fail($message);
            return false;
        }
    }

    private function generateVideo(Scene $scene, Asset $asset, string $prompt, string $targetPath): bool
    {
        $asset->markProcessing(null);

        try {
            $result = $this->videoProvider->generateVideo($prompt, [
                'target_path' => $targetPath,
                'scene_id' => $scene->id(),
                'scene_number' => $scene->number(),
            ]);
            $asset->complete($result->path, $result->metadata);
            if ($result->duration !== null && $scene->duration() === null) {
                $scene->setDuration($result->duration);
            }
            return true;
        } catch (\Throwable $e) {
            $message = 'Video generation failed: ' . $e->getMessage();
            $asset->fail($message);
            $scene->fail($message);
            return false;
        }
    }
}

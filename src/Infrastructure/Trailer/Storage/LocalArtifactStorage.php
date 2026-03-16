<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Storage;

use App\Application\Trailer\Port\ArtifactStorageInterface;
use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Domain\Trailer\Scene;

final class LocalArtifactStorage implements ArtifactStorageInterface, TrailerProjectSetupInterface
{
    public function __construct(
        private readonly TrailerPathResolver $pathResolver,
    ) {
    }

    /**
     * Prepare project directories: input/, scenes/, render/ under var/trailers/<project-id>/
     */
    public function prepareProjectDirectories(string $projectId): void
    {
        $dirs = [
            $this->pathResolver->inputDir($projectId),
            $this->pathResolver->renderDir($projectId),
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * Copy input YAML into the project input folder as definition.yaml.
     * Returns the full path of the copied file.
     */
    public function copyInputYaml(string $projectId, string $sourcePath): string
    {
        $this->prepareProjectDirectories($projectId);
        $targetPath = $this->pathResolver->inputDefinitionPath($projectId);
        if (!is_dir(\dirname($targetPath))) {
            mkdir(\dirname($targetPath), 0o755, true);
        }
        copy($sourcePath, $targetPath);
        return $targetPath;
    }

    /**
     * Target path for scene voice output: scenes/<scene-number>-<scene-id>/voice.mp3
     */
    public function getSceneVoiceOutputPath(string $projectId, Scene $scene): string
    {
        return $this->pathResolver->sceneVoicePath(
            $projectId,
            $scene->number(),
            $scene->id(),
        );
    }

    /**
     * Target path for scene video output: scenes/<scene-number>-<scene-id>/video.mp4
     */
    public function getSceneVideoOutputPath(string $projectId, Scene $scene): string
    {
        return $this->pathResolver->sceneVideoPath(
            $projectId,
            $scene->number(),
            $scene->id(),
        );
    }

    /**
     * Render output directory: var/trailers/<project-id>/render/
     */
    public function getRenderOutputDir(string $projectId): string
    {
        return $this->pathResolver->renderDir($projectId);
    }

    /**
     * Path to the final render output file: render/final.mp4
     */
    public function getRenderOutputPath(string $projectId): string
    {
        return $this->pathResolver->renderOutputPath($projectId);
    }

    /**
     * Ensure scene directory exists. Call before writing voice/video for a scene.
     */
    public function ensureSceneDirectory(string $projectId, Scene $scene): void
    {
        $dir = $this->pathResolver->sceneDir(
            $projectId,
            $scene->number(),
            $scene->id(),
        );
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
    }

    public function put(string $key, string $sourcePath): string
    {
        $targetPath = $this->pathResolver->keyToPath($key);
        $dir = \dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        copy($sourcePath, $targetPath);
        return $targetPath;
    }

    public function getPath(string $key): ?string
    {
        $path = $this->pathResolver->keyToPath($key);
        return file_exists($path) ? $path : null;
    }

    public function exists(string $key): bool
    {
        return file_exists($this->pathResolver->keyToPath($key));
    }
}

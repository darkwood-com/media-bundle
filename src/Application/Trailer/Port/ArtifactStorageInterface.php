<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

use App\Domain\Trailer\Scene;

interface ArtifactStorageInterface
{
    /**
     * Store a file from the given local path under the given key.
     * Returns the path or identifier where the artifact can be retrieved.
     */
    public function put(string $key, string $sourcePath): string;

    /**
     * Get the path to a stored artifact, or null if not found.
     */
    public function getPath(string $key): ?string;

    public function exists(string $key): bool;

    /**
     * Target path for scene voice output (e.g. scenes/<n>-<id>/voice.mp3).
     */
    public function getSceneVoiceOutputPath(string $projectId, Scene $scene): string;

    /**
     * Target path for scene video output (e.g. scenes/<n>-<id>/video.mp4).
     */
    public function getSceneVideoOutputPath(string $projectId, Scene $scene): string;

    /**
     * Ensure scene directory exists before writing voice/video artifacts.
     */
    public function ensureSceneDirectory(string $projectId, Scene $scene): void;
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Storage;

/**
 * Centralizes path generation for trailer artifacts under var/trailers/<project-id>/.
 */
final class TrailerPathResolver
{
    private const TRAILERS_BASE = 'var/trailers';
    private const INPUT_DIR = 'input';
    private const SCENES_DIR = 'scenes';
    private const RENDER_DIR = 'render';
    private const INPUT_DEFINITION_FILE = 'definition.yaml';
    private const SCENE_VOICE_FILE = 'voice.mp3';
    private const SCENE_VIDEO_FILE = 'video.mp4';
    private const RENDER_OUTPUT_FILE = 'final.mp4';

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * Base directory for a project: var/trailers/<project-id>
     */
    public function baseDir(string $projectId): string
    {
        return $this->projectDir . '/' . self::TRAILERS_BASE . '/' . $projectId;
    }

    /**
     * Input directory: var/trailers/<project-id>/input/
     */
    public function inputDir(string $projectId): string
    {
        return $this->baseDir($projectId) . '/' . self::INPUT_DIR . '/';
    }

    /**
     * Scene directory: var/trailers/<project-id>/scenes/<scene-number>-<scene-id>/
     */
    public function sceneDir(string $projectId, int $sceneNumber, string $sceneId): string
    {
        $segment = $sceneNumber . '-' . $sceneId;
        return $this->baseDir($projectId) . '/' . self::SCENES_DIR . '/' . $segment . '/';
    }

    /**
     * Render directory: var/trailers/<project-id>/render/
     */
    public function renderDir(string $projectId): string
    {
        return $this->baseDir($projectId) . '/' . self::RENDER_DIR . '/';
    }

    /**
     * Path to the copied input definition YAML: input/definition.yaml
     */
    public function inputDefinitionPath(string $projectId): string
    {
        return $this->inputDir($projectId) . self::INPUT_DEFINITION_FILE;
    }

    /**
     * Path to scene voice output: scenes/<n>-<id>/voice.mp3
     */
    public function sceneVoicePath(string $projectId, int $sceneNumber, string $sceneId): string
    {
        return $this->sceneDir($projectId, $sceneNumber, $sceneId) . self::SCENE_VOICE_FILE;
    }

    /**
     * Path to scene video output: scenes/<n>-<id>/video.mp4
     */
    public function sceneVideoPath(string $projectId, int $sceneNumber, string $sceneId): string
    {
        return $this->sceneDir($projectId, $sceneNumber, $sceneId) . self::SCENE_VIDEO_FILE;
    }

    /**
     * Path to final render output: render/final.mp4
     */
    public function renderOutputPath(string $projectId): string
    {
        return $this->renderDir($projectId) . self::RENDER_OUTPUT_FILE;
    }

    /**
     * Relative key for a full path (for interface key-based methods).
     * Returns the key that would resolve to the given path, or null if not under trailers base.
     */
    public function pathToKey(string $fullPath): ?string
    {
        $base = $this->projectDir . '/' . self::TRAILERS_BASE . '/';
        if (str_starts_with($fullPath, $base)) {
            return substr($fullPath, strlen($base));
        }
        return null;
    }

    /**
     * Resolve a key to full filesystem path.
     */
    public function keyToPath(string $key): string
    {
        return $this->projectDir . '/' . self::TRAILERS_BASE . '/' . $key;
    }
}

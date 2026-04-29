<?php

declare(strict_types=1);

namespace App\Application\Video\Port;

use App\Application\Video\DTO\VideoGenerationResult;

/**
 * Entry point used by the video generate CLI and other callers.
 */
interface VideoGenerationOrchestratorInterface
{
    /**
     * @param array<string, mixed>|null $firstSceneVideoOptions
     */
    public function generateFromYaml(string $yamlPath, ?array $firstSceneVideoOptions = null): VideoGenerationResult;
}

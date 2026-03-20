<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

use App\Application\Trailer\DTO\TrailerGenerationResult;

/**
 * Entry point used by the trailer generate CLI and other callers.
 */
interface TrailerGenerationOrchestratorInterface
{
    /**
     * @param array<string, mixed>|null $firstSceneVideoOptions
     */
    public function generateFromYaml(string $yamlPath, ?array $firstSceneVideoOptions = null): TrailerGenerationResult;
}

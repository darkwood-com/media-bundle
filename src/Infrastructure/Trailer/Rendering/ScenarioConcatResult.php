<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Rendering;

/**
 * Outcome of building render/scenario.mp4 from per-scene scene.mp4 clips.
 */
final readonly class ScenarioConcatResult
{
    /**
     * @param list<array{scene_number: int, scene_id: string}> $scenesIncludedInScenario
     * @param list<array{scene_number: int, scene_id: string, reason: string}> $scenesExcludedFromScenario
     */
    public function __construct(
        public ?string $outputPath,
        public ?string $skipReason,
        public array $scenesIncludedInScenario = [],
        public array $scenesExcludedFromScenario = [],
    ) {
    }
}

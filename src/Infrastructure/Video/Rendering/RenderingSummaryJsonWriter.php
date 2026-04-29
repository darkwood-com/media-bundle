<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Rendering;

/**
 * Writes render/rendering-summary.json for inspection (scene clip vs scenario decisions).
 */
final class RenderingSummaryJsonWriter
{
    private const FILENAME = 'rendering-summary.json';

    /**
     * @param list<SceneClipRenderReport> $clipReports
     */
    public function write(string $renderDir, array $clipReports, ScenarioConcatResult $scenario): void
    {
        if (!is_dir($renderDir)) {
            mkdir($renderDir, 0o755, true);
        }

        $path = $renderDir . '/' . self::FILENAME;
        $payload = [
            'scene_clips' => array_map(
                static fn (SceneClipRenderReport $r): array => $r->toArray(),
                $clipReports,
            ),
            'scenario' => [
                'output_path' => $scenario->outputPath,
                'skip_reason' => $scenario->skipReason,
                'included_in_scenario' => $scenario->scenesIncludedInScenario,
                'excluded_from_scenario' => $scenario->scenesExcludedFromScenario,
            ],
        ];

        file_put_contents(
            $path,
            json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }
}

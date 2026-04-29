<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Video\Rendering;

use App\Infrastructure\Video\Rendering\SceneClipRenderReport;
use PHPUnit\Framework\TestCase;

final class SceneClipRenderReportTest extends TestCase
{
    public function test_sort_by_scene_number_matches_finalize_and_scenario_ordering(): void
    {
        $reports = [
            new SceneClipRenderReport('c', 3, SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY),
            new SceneClipRenderReport('a', 1, SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY),
            new SceneClipRenderReport('b', 2, SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY),
        ];

        SceneClipRenderReport::sortBySceneNumber($reports);

        self::assertSame([1, 2, 3], array_map(static fn (SceneClipRenderReport $r): int => $r->sceneNumber, $reports));
    }
}

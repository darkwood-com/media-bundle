<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Video\Rendering;

use App\Infrastructure\Video\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Video\Rendering\ScenarioConcatResult;
use App\Infrastructure\Video\Rendering\SceneClipRenderReport;
use PHPUnit\Framework\TestCase;

final class RenderingSummaryJsonWriterTest extends TestCase
{
    public function test_writes_json_with_scene_and_scenario_sections(): void
    {
        $dir = sys_get_temp_dir() . '/dw-summary-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0o755, true));

        try {
            $writer = new RenderingSummaryJsonWriter();
            $clipReports = [
                new SceneClipRenderReport('a', 1, SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY),
            ];
            $scenario = new ScenarioConcatResult(
                $dir . '/scenario.mp4',
                null,
                [['scene_number' => 1, 'scene_id' => 'a']],
                [['scene_number' => 2, 'scene_id' => 'b', 'reason' => 'scene_mp4_missing']],
            );

            $writer->write($dir, $clipReports, $scenario);

            $path = $dir . '/rendering-summary.json';
            self::assertFileExists($path);
            $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame('rendered_video_only', $data['scene_clips'][0]['outcome']);
            self::assertSame('a', $data['scenario']['included_in_scenario'][0]['scene_id']);
            self::assertSame('scene_mp4_missing', $data['scenario']['excluded_from_scenario'][0]['reason']);
        } finally {
            if (is_file($dir . '/rendering-summary.json')) {
                unlink($dir . '/rendering-summary.json');
            }
            rmdir($dir);
        }
    }
}

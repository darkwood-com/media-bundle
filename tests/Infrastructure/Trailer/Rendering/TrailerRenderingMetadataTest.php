<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Rendering;

use App\Domain\Trailer\Scene;
use App\Infrastructure\Trailer\Rendering\ScenarioConcatResult;
use App\Infrastructure\Trailer\Rendering\SceneClipRenderReport;
use App\Infrastructure\Trailer\Rendering\TrailerRenderingMetadata;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use App\Infrastructure\Trailer\Storage\TrailerPathResolver;
use PHPUnit\Framework\TestCase;

final class TrailerRenderingMetadataTest extends TestCase
{
    public function test_scene_clip_persist_skipped_not_completed(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-meta-' . bin2hex(random_bytes(4));
        $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
        $scene = new Scene(id: 'a', number: 1, title: 'A');
        $report = new SceneClipRenderReport(
            'a',
            1,
            SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
        );

        $row = TrailerRenderingMetadata::sceneClipPersist($report, $storage, 'pid', $scene);

        self::assertNull($row['scene_mp4_path']);
        self::assertSame('scene_not_completed', $row['skip_reason']);
        self::assertFalse($row['used_voice']);
        self::assertNull($row['audio_mode']);
    }

    public function test_scene_clip_persist_rendered_with_voice_mux_fallback_detail(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-meta-' . bin2hex(random_bytes(4));
        $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
        $sceneDir = $tmp . '/var/trailers/pid/scenes/1-a/';
        self::assertTrue(mkdir($sceneDir, 0o755, true));
        $clip = $sceneDir . 'scene.mp4';
        file_put_contents($clip, 'fake');

        $scene = new Scene(id: 'a', number: 1, title: 'A');
        $report = new SceneClipRenderReport(
            'a',
            1,
            SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY,
            ['voice_mux_failed' => true],
        );

        $row = TrailerRenderingMetadata::sceneClipPersist($report, $storage, 'pid', $scene);

        self::assertSame($clip, $row['scene_mp4_path']);
        self::assertTrue($row['used_voice'] === false);
        self::assertSame('silent_fallback_after_voice_mux_failed', $row['audio_mode']);
        self::assertNull($row['skip_reason']);
    }

    public function test_project_rendering_from_scenario(): void
    {
        $r = new ScenarioConcatResult(
            '/out/scenario.mp4',
            null,
            [['scene_number' => 1, 'scene_id' => 'a']],
            [['scene_number' => 2, 'scene_id' => 'b', 'reason' => 'scene_mp4_empty']],
        );

        $row = TrailerRenderingMetadata::projectRenderingFromScenario($r);

        self::assertSame('/out/scenario.mp4', $row['scenario_mp4_path']);
        self::assertNull($row['scenario_skip_reason']);
        self::assertCount(1, $row['scenes_included_in_scenario']);
        self::assertCount(1, $row['scenes_excluded_from_scenario']);
    }

    public function test_scene_clip_persist_rendered_with_voice(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-meta-' . bin2hex(random_bytes(4));
        $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
        $sceneDir = $tmp . '/var/trailers/pid/scenes/1-a/';
        self::assertTrue(mkdir($sceneDir, 0o755, true));
        $clip = $sceneDir . 'scene.mp4';
        file_put_contents($clip, 'x');

        $scene = new Scene(id: 'a', number: 1, title: 'A');
        $report = new SceneClipRenderReport(
            'a',
            1,
            SceneClipRenderReport::OUTCOME_RENDERED_WITH_VOICE,
        );

        $row = TrailerRenderingMetadata::sceneClipPersist($report, $storage, 'pid', $scene);

        self::assertSame($clip, $row['scene_mp4_path']);
        self::assertNull($row['skip_reason']);
        self::assertTrue($row['used_voice']);
        self::assertSame('voice_muxed', $row['audio_mode']);
    }

    public function test_scene_clip_persist_rendered_silent_video_only(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-meta-' . bin2hex(random_bytes(4));
        $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
        $sceneDir = $tmp . '/var/trailers/pid/scenes/1-a/';
        self::assertTrue(mkdir($sceneDir, 0o755, true));
        $clip = $sceneDir . 'scene.mp4';
        file_put_contents($clip, 'x');

        $scene = new Scene(id: 'a', number: 1, title: 'A');
        $report = new SceneClipRenderReport(
            'a',
            1,
            SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY,
        );

        $row = TrailerRenderingMetadata::sceneClipPersist($report, $storage, 'pid', $scene);

        self::assertSame($clip, $row['scene_mp4_path']);
        self::assertNull($row['skip_reason']);
        self::assertFalse($row['used_voice']);
        self::assertSame('silent_video_only', $row['audio_mode']);
    }

    public function test_scene_clip_persist_skipped_no_usable_video(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-meta-' . bin2hex(random_bytes(4));
        $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
        $scene = new Scene(id: 'a', number: 1, title: 'A');
        $report = new SceneClipRenderReport(
            'a',
            1,
            SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO,
        );

        $row = TrailerRenderingMetadata::sceneClipPersist($report, $storage, 'pid', $scene);

        self::assertNull($row['scene_mp4_path']);
        self::assertSame('no_usable_video', $row['skip_reason']);
        self::assertFalse($row['used_voice']);
        self::assertNull($row['audio_mode']);
    }

    public function test_scene_clip_persist_skipped_ffmpeg_failed(): void
    {
        $tmp = sys_get_temp_dir() . '/dw-meta-' . bin2hex(random_bytes(4));
        $storage = new LocalArtifactStorage(new TrailerPathResolver($tmp));
        $scene = new Scene(id: 'a', number: 1, title: 'A');
        $report = new SceneClipRenderReport(
            'a',
            1,
            SceneClipRenderReport::OUTCOME_SKIPPED_FFMPEG_FAILED,
        );

        $row = TrailerRenderingMetadata::sceneClipPersist($report, $storage, 'pid', $scene);

        self::assertNull($row['scene_mp4_path']);
        self::assertSame('ffmpeg_failed', $row['skip_reason']);
        self::assertFalse($row['used_voice']);
        self::assertNull($row['audio_mode']);
    }

    public function test_project_rendering_from_scenario_when_skipped(): void
    {
        $r = new ScenarioConcatResult(
            null,
            'no clips',
            [],
            [['scene_number' => 1, 'scene_id' => 'a', 'reason' => 'scene_mp4_missing']],
        );

        $row = TrailerRenderingMetadata::projectRenderingFromScenario($r);

        self::assertNull($row['scenario_mp4_path']);
        self::assertSame('no clips', $row['scenario_skip_reason']);
        self::assertSame([], $row['scenes_included_in_scenario']);
        self::assertCount(1, $row['scenes_excluded_from_scenario']);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Rendering;

use App\Domain\Video\Scene;
use App\Infrastructure\Video\Storage\LocalArtifactStorage;

/**
 * Shapes clip/scenario render decisions for persistence on VideoProject / Scene (project.json).
 */
final class VideoRenderingMetadata
{
    /**
     * @return array<string, mixed>
     */
    public static function sceneClipPersist(
        SceneClipRenderReport $report,
        LocalArtifactStorage $storage,
        string $projectId,
        Scene $scene,
    ): array {
        $base = $report->toArray();
        $rendered = \in_array(
            $report->outcome,
            [
                SceneClipRenderReport::OUTCOME_RENDERED_WITH_VOICE,
                SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY,
            ],
            true,
        );

        $sceneMp4Path = null;
        if ($rendered) {
            $p = $storage->getSceneClipOutputPath($projectId, $scene);
            $sceneMp4Path = is_file($p) ? $p : null;
        }

        $base['scene_mp4_path'] = $sceneMp4Path;
        $base['skip_reason'] = self::clipSkipReason($report->outcome);
        $base['used_voice'] = $report->outcome === SceneClipRenderReport::OUTCOME_RENDERED_WITH_VOICE;
        $base['audio_mode'] = self::clipAudioMode($report);

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function projectRenderingFromScenario(ScenarioConcatResult $result): array
    {
        return [
            'scenario_mp4_path' => $result->outputPath,
            'scenario_skip_reason' => $result->skipReason,
            'scenes_included_in_scenario' => $result->scenesIncludedInScenario,
            'scenes_excluded_from_scenario' => $result->scenesExcludedFromScenario,
        ];
    }

    private static function clipSkipReason(string $outcome): ?string
    {
        return match ($outcome) {
            SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED => 'scene_not_completed',
            SceneClipRenderReport::OUTCOME_SKIPPED_NO_USABLE_VIDEO => 'no_usable_video',
            SceneClipRenderReport::OUTCOME_SKIPPED_SCENE_MP4_NOT_USABLE => 'scene_mp4_missing_or_corrupt',
            SceneClipRenderReport::OUTCOME_SKIPPED_FFMPEG_FAILED => 'ffmpeg_failed',
            default => null,
        };
    }

    private static function clipAudioMode(SceneClipRenderReport $report): ?string
    {
        return match ($report->outcome) {
            SceneClipRenderReport::OUTCOME_RENDERED_WITH_VOICE => 'voice_muxed',
            SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY => (($report->details['voice_mux_failed'] ?? false) === true)
                ? 'silent_fallback_after_voice_mux_failed'
                : 'silent_video_only',
            default => null,
        };
    }
}

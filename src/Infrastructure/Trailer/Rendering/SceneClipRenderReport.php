<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Rendering;

/**
 * Per-scene outcome for scene.mp4 rendering (distinct from scenario inclusion).
 */
final readonly class SceneClipRenderReport
{
    public const OUTCOME_SKIPPED_NOT_COMPLETED = 'skipped_scene_not_completed';

    public const OUTCOME_SKIPPED_NO_USABLE_VIDEO = 'skipped_no_usable_video';

    public const OUTCOME_SKIPPED_SCENE_MP4_NOT_USABLE = 'skipped_scene_mp4_not_usable';

    public const OUTCOME_RENDERED_WITH_VOICE = 'rendered_with_voice';

    public const OUTCOME_RENDERED_VIDEO_ONLY = 'rendered_video_only';

    public const OUTCOME_SKIPPED_FFMPEG_FAILED = 'skipped_scene_ffmpeg_failed';

    /**
     * @param array<string, bool|string|int|float|null> $details
     */
    public function __construct(
        public string $sceneId,
        public int $sceneNumber,
        public string $outcome,
        public array $details = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scene_id' => $this->sceneId,
            'scene_number' => $this->sceneNumber,
            'outcome' => $this->outcome,
            'details' => $this->details,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Flow\Model;

use App\Infrastructure\Video\Rendering\SceneClipRenderReport;

/**
 * Ip payload for a single scene step. Used by {@see VideoSceneGenerationFlow} when
 * scene work is dispatched as its own Flow (async-friendly parent orchestration).
 */
final class VideoScenePayload
{
    public function __construct(
        public VideoGenerationPayload $generation,
        public int $sceneIndex,
        public ?SceneClipRenderReport $clipReport = null,
    ) {
    }
}

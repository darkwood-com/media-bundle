<?php

declare(strict_types=1);

namespace App\Flow\Model;

use App\Infrastructure\Trailer\Rendering\SceneClipRenderReport;

/**
 * Ip payload for a single scene step. Used by {@see TrailerSceneGenerationFlow} when
 * scene work is dispatched as its own Flow (async-friendly parent orchestration).
 */
final class TrailerScenePayload
{
    public function __construct(
        public TrailerGenerationPayload $generation,
        public int $sceneIndex,
        public ?SceneClipRenderReport $clipReport = null,
    ) {
    }
}

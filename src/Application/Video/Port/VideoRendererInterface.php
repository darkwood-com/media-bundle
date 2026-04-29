<?php

declare(strict_types=1);

namespace App\Application\Video\Port;

use App\Domain\Video\VideoProject;

interface VideoRendererInterface
{
    /**
     * Render the full video from the project's completed assets to the given output path.
     * Returns the path to the rendered file (e.g. final video or manifest).
     */
    public function render(VideoProject $project, string $outputPath): string;
}

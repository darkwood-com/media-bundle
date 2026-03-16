<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

use App\Domain\Trailer\TrailerProject;

interface TrailerRendererInterface
{
    /**
     * Render the full trailer from the project's completed assets to the given output path.
     * Returns the path to the rendered file (e.g. final video or manifest).
     */
    public function render(TrailerProject $project, string $outputPath): string;
}

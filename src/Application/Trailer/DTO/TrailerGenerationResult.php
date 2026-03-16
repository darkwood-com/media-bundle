<?php

declare(strict_types=1);

namespace App\Application\Trailer\DTO;

use App\Domain\Trailer\TrailerProject;

final readonly class TrailerGenerationResult
{
    public function __construct(
        public TrailerProject $project,
        public ?string $renderOutputPath = null,
    ) {
    }
}

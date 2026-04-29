<?php

declare(strict_types=1);

namespace App\Application\Video\DTO;

final readonly class SceneDefinition
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description = '',
        public string $videoPrompt = '',
        public string $narration = '',
        public ?float $duration = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Trailer\DTO;

final readonly class TrailerDefinition
{
    /**
     * @param list<SceneDefinition> $scenes
     */
    public function __construct(
        public string $title,
        public array $scenes,
    ) {
    }
}

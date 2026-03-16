<?php

declare(strict_types=1);

namespace App\Application\Trailer\DTO;

final readonly class GeneratedAssetResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $path,
        public ?float $duration = null,
        public array $metadata = [],
    ) {
    }
}

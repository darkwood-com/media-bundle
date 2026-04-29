<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Provider\Replicate;

/**
 * Shared Replicate HTTP settings (token + API root). Video and future voice
 * providers can reuse the same client without duplicating auth wiring.
 */
final class ReplicateApiConfig
{
    public function __construct(
        public readonly string $apiToken,
        public readonly string $baseUrl = 'https://api.replicate.com/v1',
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider\Replicate;

/**
 * Small value object holding Replicate video provider configuration.
 *
 * This is wired from env vars in Symfony configuration so that
 * services can depend on a single typed object instead of reading
 * parameters directly.
 */
final class ReplicateVideoProviderConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $apiToken,
        public readonly string $model,
        public readonly int $pollIntervalSeconds,
        public readonly int $maxAttempts,
    ) {
    }
}


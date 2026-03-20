<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider\Replicate;

/**
 * Video-specific Replicate settings (polling, defaults). Authentication uses
 * {@see ReplicateApiConfig} on {@see ReplicateClient}.
 */
final class ReplicateVideoProviderConfig
{
    public function __construct(
        public readonly bool $enabled,
        /** Raw model/version slug when no preset is active (see TRAILER_VIDEO_REPLICATE_MODEL). */
        public readonly string $model,
        /**
         * Optional preset key (e.g. hailuo) used when the call does not pass replicate_preset.
         * Empty string disables this fallback.
         */
        public readonly string $defaultPreset,
        public readonly int $pollIntervalSeconds,
        public readonly int $maxAttempts,
        /**
         * Wall-clock cap for polling; 0 = rely on maxAttempts only.
         */
        public readonly int $maxPollDurationSeconds,
    ) {
    }
}

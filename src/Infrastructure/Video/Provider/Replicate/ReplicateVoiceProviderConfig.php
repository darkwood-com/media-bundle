<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Provider\Replicate;

/**
 * Voice (TTS) Replicate settings. Authentication uses {@see ReplicateApiConfig} on {@see ReplicateClient}.
 */
final class ReplicateVoiceProviderConfig
{
    public function __construct(
        public readonly bool $enabled,
        /**
         * Logical model slug (e.g. minimax/speech-2.6-turbo). Resolved to a version id via the Replicate models API when needed.
         */
        public readonly string $model,
        /** MiniMax voice catalogue id (e.g. Wise_Woman). Must not be a placeholder — validated in the provider. */
        public readonly string $voiceId,
        public readonly string $audioFormat,
        public readonly int $pollIntervalSeconds,
        public readonly int $maxAttempts,
        /**
         * Wall-clock cap for polling; 0 = rely on maxAttempts only.
         */
        public readonly int $maxPollDurationSeconds,
    ) {
    }
}

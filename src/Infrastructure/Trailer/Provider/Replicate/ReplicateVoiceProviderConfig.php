<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider\Replicate;

/**
 * Voice (TTS) Replicate settings. Authentication uses {@see ReplicateApiConfig} on {@see ReplicateClient}.
 */
final class ReplicateVoiceProviderConfig
{
    public function __construct(
        public readonly bool $enabled,
        /** Model/version slug for create-prediction (e.g. minimax/speech-2.6-turbo). */
        public readonly string $model,
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

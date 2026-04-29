<?php

declare(strict_types=1);

namespace App\Application\Video\Service;

use App\Infrastructure\Video\Provider\Replicate\ReplicatePredictionFailedException;

/**
 * Normalized failure fields for persisted asset metadata after provider errors.
 */
final class ProviderFailureMetadata
{
    /**
     * @return array<string, mixed>
     */
    public static function forThrowable(\Throwable $e): array
    {
        $meta = [
            'provider_state' => 'error',
            'provider_error_message' => $e->getMessage(),
            'failure_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
        ];

        if ($e instanceof ReplicatePredictionFailedException) {
            $meta['prediction_id'] = $e->predictionId();
            $meta['remote_job_id'] = $e->predictionId();
            $meta['provider_model'] = $e->model();
            $preset = $e->replicatePreset();
            if ($preset !== null && $preset !== '') {
                $meta['replicate_preset'] = $preset;
            }
            $meta['remote_status'] = $e->remoteStatus();
            $detail = $e->remoteError();
            if ($detail !== null && $detail !== '') {
                $meta['remote_error_detail'] = $detail;
            }
        }

        return $meta;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Provider\Replicate;

/**
 * Thrown when a Replicate prediction ends without a downloadable success output.
 * Lets upper layers persist prediction id, model, and remote status without parsing messages.
 */
final class ReplicatePredictionFailedException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $predictionId,
        private readonly string $model,
        private readonly string $remoteStatus,
        private readonly ?string $remoteError = null,
        private readonly ?string $replicatePreset = null,
    ) {
        parent::__construct($message, 0, null);
    }

    public function predictionId(): string
    {
        return $this->predictionId;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function remoteStatus(): string
    {
        return $this->remoteStatus;
    }

    public function remoteError(): ?string
    {
        return $this->remoteError;
    }

    public function replicatePreset(): ?string
    {
        return $this->replicatePreset;
    }

    /**
     * @param mixed $error Raw Replicate `error` field
     */
    public static function terminalPredictionFailure(
        string $predictionId,
        string $model,
        string $status,
        mixed $error,
        ?string $replicatePreset = null,
    ): self {
        $detail = self::stringifyRemoteError($error);
        $message = sprintf(
            'Replicate prediction %s failed with status "%s"%s',
            $predictionId,
            $status,
            $detail !== null && $detail !== '' ? ': ' . $detail : ''
        );

        return new self(
            message: $message,
            predictionId: $predictionId,
            model: $model,
            remoteStatus: $status,
            remoteError: $detail,
            replicatePreset: $replicatePreset,
        );
    }

    private static function stringifyRemoteError(mixed $error): ?string
    {
        if ($error === null) {
            return null;
        }
        if (is_string($error)) {
            return $error !== '' ? $error : null;
        }
        if (is_scalar($error)) {
            return (string) $error;
        }

        $json = json_encode($error);

        return $json !== false ? $json : null;
    }
}

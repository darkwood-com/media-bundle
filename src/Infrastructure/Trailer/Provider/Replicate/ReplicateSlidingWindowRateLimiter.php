<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider\Replicate;

/**
 * File-backed sliding-window limiter so rate limits apply across forked PHP processes (Spatie Fork).
 * Defaults stay slightly under Replicate's documented caps (600 prediction creates / min, 3000 other / min).
 */
final class ReplicateSlidingWindowRateLimiter
{
    private const WINDOW_SECONDS = 60.0;

    public function __construct(
        private readonly string $stateFilePath,
        private readonly int $predictionCreatesPerMinute,
        private readonly int $otherApiCallsPerMinute,
    ) {
    }

    /**
     * Blocks until a prediction create (POST /predictions) may proceed.
     */
    public function acquireBeforePredictionCreate(): void
    {
        $this->acquireSlot('prediction_timestamps', $this->predictionCreatesPerMinute);
    }

    /**
     * Blocks until a non-create call (GET prediction, GET model, etc.) may proceed.
     */
    public function acquireBeforeOtherApiCall(): void
    {
        $this->acquireSlot('other_timestamps', $this->otherApiCallsPerMinute);
    }

    /**
     * @param 'prediction_timestamps'|'other_timestamps' $key
     */
    private function acquireSlot(string $key, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $dir = \dirname($this->stateFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        $lockPath = $this->stateFilePath . '.lock';
        $maxIterations = 50_000;
        $wait = 0.05;

        for ($i = 0; $i < $maxIterations; ++$i) {
            $fh = fopen($lockPath, 'c+');
            if ($fh === false) {
                throw new \RuntimeException(sprintf('Replicate rate limiter could not open lock file "%s".', $lockPath));
            }

            if (!flock($fh, \LOCK_EX)) {
                fclose($fh);
                throw new \RuntimeException(sprintf('Replicate rate limiter could not acquire lock "%s".', $lockPath));
            }

            try {
                $data = $this->readState();
                $timestamps = $data[$key] ?? [];
                if (!is_array($timestamps)) {
                    $timestamps = [];
                }

                $now = microtime(true);
                $cutoff = $now - self::WINDOW_SECONDS;
                $timestamps = array_values(array_filter(
                    $timestamps,
                    static function (mixed $t) use ($cutoff): bool {
                        if (!is_int($t) && !is_float($t)) {
                            return false;
                        }

                        return (float) $t >= $cutoff;
                    },
                ));
                sort($timestamps);

                if (\count($timestamps) < $limit) {
                    $timestamps[] = $now;
                    $data[$key] = $timestamps;
                    $this->writeState($data);

                    return;
                }

                $oldest = $timestamps[0];
                $wait = $oldest + self::WINDOW_SECONDS - $now;
                if ($wait < 0.05) {
                    $wait = 0.05;
                }
                $wait = min($wait, 5.0);
            } finally {
                flock($fh, \LOCK_UN);
                fclose($fh);
            }

            usleep((int) ceil($wait * 1_000_000));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        if (!is_file($this->stateFilePath)) {
            return [];
        }
        $raw = @file_get_contents($this->stateFilePath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeState(array $data): void
    {
        $json = json_encode($data, \JSON_THROW_ON_ERROR);
        file_put_contents($this->stateFilePath, $json, \LOCK_EX);
    }
}

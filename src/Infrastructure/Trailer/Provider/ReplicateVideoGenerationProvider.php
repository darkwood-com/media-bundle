<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoProviderConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Real video generation provider backed by Replicate's HTTP API.
 *
 * This implementation is intentionally CLI-friendly and relies on simple polling
 * instead of webhooks. It:
 *  - submits a prediction
 *  - polls until terminal state
 *  - downloads the resulting video to a local path
 *  - returns a GeneratedAssetResult with rich provider metadata
 */
final class ReplicateVideoGenerationProvider implements VideoGenerationProviderInterface
{
    private const PROVIDER_NAME = 'replicate-video';
    private const API_BASE_URL = 'https://api.replicate.com/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ReplicateVideoProviderConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult
    {
        if (!$this->config->enabled) {
            throw new \RuntimeException('Replicate video provider is disabled by configuration.');
        }

        if ($this->config->apiToken === '' || $this->config->model === '') {
            throw new \RuntimeException('Replicate video provider is misconfigured (missing API token or model identifier).');
        }

        $targetPath = $options['target_path'] ?? $this->defaultPath($prompt, 'mp4');
        $sceneId = $options['scene_id'] ?? null;

        $startedAt = new \DateTimeImmutable('now');

        $initialPrediction = $this->createPrediction($prompt, $options);
        $predictionId = (string) ($initialPrediction['id'] ?? '');

        if ($predictionId === '') {
            throw new \RuntimeException('Replicate video provider did not return a prediction id.');
        }

        [$finalPrediction, $attempts] = $this->waitForPrediction($predictionId);

        $status = (string) ($finalPrediction['status'] ?? 'unknown');

        if (!in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
            throw new \RuntimeException(sprintf(
                'Replicate prediction %s ended in unexpected status "%s".',
                $predictionId,
                $status
            ));
        }

        if ($status !== 'succeeded') {
            $error = $finalPrediction['error'] ?? null;

            throw new \RuntimeException(sprintf(
                'Replicate prediction %s failed with status "%s"%s',
                $predictionId,
                $status,
                $error !== null ? (': ' . (is_string($error) ? $error : json_encode($error))) : ''
            ));
        }

        $output = $finalPrediction['output'] ?? null;
        $outputUrl = $this->extractOutputUrl($output);

        if ($outputUrl === null) {
            throw new \RuntimeException(sprintf(
                'Replicate prediction %s succeeded but did not return a usable output URL.',
                $predictionId
            ));
        }

        $this->downloadToPath($outputUrl, $targetPath);

        $completedAt = new \DateTimeImmutable('now');

        $metadata = [
            'provider' => self::PROVIDER_NAME,
            'provider_status' => $status,
            'prediction_id' => $predictionId,
            'model' => $this->config->model,
            'remote_output_url' => $outputUrl,
            'poll_attempts' => $attempts,
            'started_at' => $startedAt->format(\DateTimeInterface::ATOM),
            'completed_at' => $completedAt->format(\DateTimeInterface::ATOM),
            'scene_id' => $sceneId,
            'prompt' => $prompt,
        ];

        if (isset($finalPrediction['metrics']) && is_array($finalPrediction['metrics'])) {
            $metadata['metrics'] = $finalPrediction['metrics'];
        }

        return new GeneratedAssetResult(
            path: $targetPath,
            duration: null,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function createPrediction(string $prompt, array $options): array
    {
        $input = [
            'prompt' => $prompt,
        ];

        if (isset($options['duration'])) {
            $input['duration'] = (int) $options['duration'];
        }

        if (isset($options['seed'])) {
            $input['seed'] = $options['seed'];
        }

        $body = [
            'version' => $this->config->model,
            'input' => $input,
        ];

        $response = $this->httpClient->request('POST', self::API_BASE_URL . '/predictions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->apiToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function waitForPrediction(string $predictionId): array
    {
        $attempts = 0;

        while (true) {
            ++$attempts;

            $prediction = $this->getPrediction($predictionId);
            $status = (string) ($prediction['status'] ?? '');

            if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
                return [$prediction, $attempts];
            }

            if ($attempts >= $this->config->maxAttempts) {
                throw new \RuntimeException(sprintf(
                    'Replicate prediction %s did not reach a terminal state after %d attempts (last status: "%s").',
                    $predictionId,
                    $attempts,
                    $status
                ));
            }

            sleep($this->config->pollIntervalSeconds);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getPrediction(string $predictionId): array
    {
        $response = $this->httpClient->request('GET', self::API_BASE_URL . '/predictions/' . urlencode($predictionId), [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->apiToken,
            ],
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
    }

    /**
     * @param mixed $output
     */
    private function extractOutputUrl($output): ?string
    {
        if (is_string($output)) {
            return $output;
        }

        if (is_array($output)) {
            foreach ($output as $item) {
                if (is_string($item)) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function downloadToPath(string $url, string $targetPath): void
    {
        $dir = \dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $response = $this->httpClient->request('GET', $url);
        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false);

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Failed to download video from "%s" (HTTP %d).',
                $url,
                $statusCode
            ));
        }

        if (@file_put_contents($targetPath, $content) === false) {
            throw new \RuntimeException(sprintf(
                'Failed to write downloaded video to "%s".',
                $targetPath
            ));
        }
    }

    private function defaultPath(string $prompt, string $ext): string
    {
        $hash = substr(hash('xxh128', $prompt), 0, 16);

        return sys_get_temp_dir() . '/replicate_video_' . $hash . '.' . $ext;
    }
}


<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider\Replicate;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin HTTP wrapper around Replicate's predictions API and output downloads.
 * Applies sliding-window rate limits (prediction creates vs other API calls) and retries 429 with backoff.
 */
final class ReplicateClient
{
    private const MAX_429_RETRIES = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ReplicateApiConfig $apiConfig,
        private readonly ReplicateSlidingWindowRateLimiter $rateLimiter,
    ) {
    }

    public function hasApiToken(): bool
    {
        return $this->apiConfig->apiToken !== '';
    }

    /**
     * Turns a UI-style model slug into a value suitable for predictions.create `version`.
     *
     * Replicate accepts `owner/model` in `version` only for official models; community
     * models (e.g. minimax/hailuo-02-fast) need the concrete version id from the model API.
     *
     * - 64-char hex → returned as-is (version id).
     * - String containing `:` → returned as-is (`owner/name:version_id`).
     * - Exactly `owner/name` → GET /models/{owner}/{name} and use latest_version.id.
     * - Anything else → returned unchanged (caller-specific slugs in tests, etc.).
     */
    public function resolvePredictionVersion(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            throw new \InvalidArgumentException('Empty Replicate model or version identifier.');
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $model) === 1) {
            return $model;
        }

        if (str_contains($model, ':')) {
            return $model;
        }

        if (preg_match('#^([^/]+)/([^/]+)$#', $model, $m) === 1) {
            return $this->fetchLatestVersionIdForModel($m[1], $m[2]);
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $body Replicate create-prediction JSON (e.g. version + input)
     *
     * @return array<string, mixed>
     */
    public function createPrediction(array $body): array
    {
        $response = $this->requestWithRateLimitAnd429Retry(
            'POST',
            $this->endpoint('/predictions'),
            [
                'headers' => $this->jsonHeaders(),
                'json' => $body,
            ],
            'prediction create',
            'prediction',
        );

        return $this->decodeSuccessfulPredictionJsonResponse($response, 'prediction create');
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrediction(string $predictionId): array
    {
        $response = $this->requestWithRateLimitAnd429Retry(
            'GET',
            $this->endpoint('/predictions/' . rawurlencode($predictionId)),
            [
                'headers' => $this->bearerHeaders(),
            ],
            'prediction get',
            'other',
        );

        return $this->decodeSuccessfulPredictionJsonResponse($response, 'prediction get');
    }

    public function downloadToPath(string $url, string $targetPath): void
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
                'Failed to download Replicate output from "%s" (HTTP %d).',
                $url,
                $statusCode
            ));
        }

        if (@file_put_contents($targetPath, $content) === false) {
            throw new \RuntimeException(sprintf(
                'Failed to write Replicate output to "%s".',
                $targetPath
            ));
        }
    }

    /**
     * @param mixed $output
     */
    public function extractFirstOutputUrl(mixed $output): ?string
    {
        $url = $this->findFirstHttpUrl($output);
        if ($url !== null) {
            return $url;
        }

        if (is_string($output) && $output !== '') {
            return $output;
        }

        if (is_array($output)) {
            foreach ($output as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @param 'prediction'|'other' $rateKind
     */
    private function requestWithRateLimitAnd429Retry(
        string $method,
        string $url,
        array $options,
        string $contextLabel,
        string $rateKind,
    ): ResponseInterface {
        $lastStatus = 0;
        for ($attempt = 0; $attempt <= self::MAX_429_RETRIES; ++$attempt) {
            if ($rateKind === 'prediction') {
                $this->rateLimiter->acquireBeforePredictionCreate();
            } else {
                $this->rateLimiter->acquireBeforeOtherApiCall();
            }

            $response = $this->httpClient->request($method, $url, $options);
            $lastStatus = $response->getStatusCode();

            if ($lastStatus === 429) {
                $this->sleepAfter429($response, $attempt);
                continue;
            }

            return $response;
        }

        throw new \RuntimeException(sprintf(
            'Replicate %s failed: HTTP %d (too many 429 throttling responses).',
            $contextLabel,
            $lastStatus
        ));
    }

    private function sleepAfter429(ResponseInterface $response, int $attempt): void
    {
        $headers = $response->getHeaders();
        $retryAfter = $headers['retry-after'][0] ?? null;
        if (is_numeric($retryAfter)) {
            $seconds = min(120, max(1, (int) $retryAfter));
        } else {
            $seconds = min(120, 2 ** min($attempt, 6));
        }

        sleep($seconds);
    }

    private function fetchLatestVersionIdForModel(string $owner, string $name): string
    {
        $path = '/models/' . rawurlencode($owner) . '/' . rawurlencode($name);
        $response = $this->requestWithRateLimitAnd429Retry(
            'GET',
            $this->endpoint($path),
            [
                'headers' => $this->bearerHeaders(),
            ],
            'model lookup',
            'other',
        );

        $statusCode = $response->getStatusCode();
        $raw = $response->getContent(false);
        $data = $this->decodeJsonAssociative($raw);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Replicate model lookup failed for "%s/%s" (HTTP %d): %s',
                $owner,
                $name,
                $statusCode,
                self::summarizeApiPayload($data, $raw)
            ));
        }

        $latest = $data['latest_version'] ?? null;
        if (!is_array($latest)) {
            throw new \RuntimeException(sprintf(
                'Replicate model "%s/%s" returned no latest_version.',
                $owner,
                $name
            ));
        }

        $id = $latest['id'] ?? '';
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException(sprintf(
                'Replicate model "%s/%s" latest_version has no id.',
                $owner,
                $name
            ));
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSuccessfulPredictionJsonResponse(ResponseInterface $response, string $contextLabel): array
    {
        $statusCode = $response->getStatusCode();
        $raw = $response->getContent(false);
        $data = $this->decodeJsonAssociative($raw);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Replicate %s failed (HTTP %d): %s',
                $contextLabel,
                $statusCode,
                self::summarizeApiPayload($data, $raw)
            ));
        }

        return $data;
    }

    /**
     * @param mixed $value
     */
    private function findFirstHttpUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->isHttpUrlString($value) ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            $found = $this->findFirstHttpUrl($item);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function isHttpUrlString(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonAssociative(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function summarizeApiPayload(array $data, string $raw): string
    {
        foreach (['detail', 'message', 'title'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                return $data[$key];
            }
        }

        if (array_key_exists('error', $data)) {
            $err = $data['error'];
            if (is_string($err) && $err !== '') {
                return $err;
            }
            if ($err !== null && is_scalar($err)) {
                return (string) $err;
            }
            if (is_array($err)) {
                $enc = json_encode($err);

                return $enc !== false ? $enc : 'error object';
            }
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '(empty response body)';
        }

        if (strlen($trimmed) <= 2000) {
            return $trimmed;
        }

        return substr($trimmed, 0, 2000) . '…';
    }

    /**
     * @return array<string, string>
     */
    private function jsonHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiConfig->apiToken,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function bearerHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiConfig->apiToken,
        ];
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->apiConfig->baseUrl, '/') . $path;
    }
}

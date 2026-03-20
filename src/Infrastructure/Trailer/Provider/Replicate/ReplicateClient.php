<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider\Replicate;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin HTTP wrapper around Replicate's predictions API and output downloads.
 * Keeps orchestration/providers free of URLs, headers, and response parsing.
 */
final class ReplicateClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ReplicateApiConfig $apiConfig,
    ) {
    }

    public function hasApiToken(): bool
    {
        return $this->apiConfig->apiToken !== '';
    }

    /**
     * @param array<string, mixed> $body Replicate create-prediction JSON (e.g. version + input)
     *
     * @return array<string, mixed>
     */
    public function createPrediction(array $body): array
    {
        $response = $this->httpClient->request('POST', $this->endpoint('/predictions'), [
            'headers' => $this->jsonHeaders(),
            'json' => $body,
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrediction(string $predictionId): array
    {
        $response = $this->httpClient->request('GET', $this->endpoint('/predictions/' . rawurlencode($predictionId)), [
            'headers' => $this->bearerHeaders(),
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
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

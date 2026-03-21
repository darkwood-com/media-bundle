<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Provider;

use App\Infrastructure\Trailer\Provider\Replicate\ReplicateApiConfig;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateClient;
use App\Tests\Support\ReplicateTestRateLimiterFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ReplicateClientTest extends TestCase
{
    public function test_resolve_prediction_version_passes_through_64_char_hex(): void
    {
        $hex = str_repeat('a', 64);
        $client = new ReplicateClient(
            $this->createMock(HttpClientInterface::class),
            new ReplicateApiConfig('t'),
            ReplicateTestRateLimiterFactory::create(),
        );

        self::assertSame($hex, $client->resolvePredictionVersion($hex));
    }

    public function test_resolve_prediction_version_fetches_latest_version_for_owner_model_slug(): void
    {
        $resolved = str_repeat('f', 64);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->jsonResponse([
            'latest_version' => ['id' => $resolved],
        ]);

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.replicate.com/v1/models/acme/cool-model',
                self::anything(),
            )
            ->willReturn($response);

        $client = new ReplicateClient($httpClient, new ReplicateApiConfig('token'), ReplicateTestRateLimiterFactory::create());

        self::assertSame($resolved, $client->resolvePredictionVersion('acme/cool-model'));
    }

    public function test_create_prediction_non_2xx_includes_replicate_detail_in_exception(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $response = $this->jsonResponse(['detail' => 'version is invalid'], 422);

        $httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($response);

        $client = new ReplicateClient($httpClient, new ReplicateApiConfig('token'), ReplicateTestRateLimiterFactory::create());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prediction create failed');
        $this->expectExceptionMessage('HTTP 422');
        $this->expectExceptionMessage('version is invalid');

        $client->createPrediction(['version' => 'x', 'input' => []]);
    }

    public function test_extract_first_output_url_finds_nested_https_url(): void
    {
        $client = new ReplicateClient(
            $this->createMock(HttpClientInterface::class),
            new ReplicateApiConfig(''),
            ReplicateTestRateLimiterFactory::create(),
        );

        $url = $client->extractFirstOutputUrl([
            'file' => ['nested' => ['https://cdn.example.com/out.mp4']],
        ]);

        self::assertSame('https://cdn.example.com/out.mp4', $url);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data, int $status = 200): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getContent')->with(false)->willReturn(json_encode($data, JSON_THROW_ON_ERROR));

        return $response;
    }
}

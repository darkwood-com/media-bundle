<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Video\Provider;

use App\Application\Video\DTO\GeneratedAssetResult;
use App\Infrastructure\Video\Provider\Replicate\ReplicateApiConfig;
use App\Infrastructure\Video\Provider\Replicate\ReplicateClient;
use App\Tests\Support\ReplicateTestRateLimiterFactory;
use App\Infrastructure\Video\Provider\Replicate\ReplicatePredictionFailedException;
use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoInputMapper;
use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoModelPresets;
use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoProviderConfig;
use App\Infrastructure\Video\Provider\ReplicateVideoGenerationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ReplicateVideoGenerationProviderTest extends TestCase
{
    public function test_generate_video_happy_path_with_mocked_http(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $createResponse = $this->jsonHttpResponse([
            'id' => 'pred-123',
            'status' => 'starting',
        ]);
        $pollResponse1 = $this->jsonHttpResponse([
            'id' => 'pred-123',
            'status' => 'processing',
        ]);
        $pollResponse2 = $this->jsonHttpResponse([
            'id' => 'pred-123',
            'status' => 'succeeded',
            'output' => ['https://cdn.example.com/video.mp4'],
            'metrics' => ['test_metric' => 1],
        ]);
        $downloadResponse = $this->createMock(ResponseInterface::class);

        $downloadResponse
            ->method('getStatusCode')
            ->willReturn(200);

        $downloadResponse
            ->method('getContent')
            ->with(false)
            ->willReturn('FAKE-VIDEO-DATA');

        $postJson = null;
        $pollCount = 0;
        $httpClient
            ->expects($this->exactly(4))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $opts = []) use (
                &$postJson,
                &$pollCount,
                $createResponse,
                $pollResponse1,
                $pollResponse2,
                $downloadResponse
            ) {
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    $postJson = $opts['json'] ?? null;

                    return $createResponse;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-123')) {
                    ++$pollCount;

                    return $pollCount === 1 ? $pollResponse1 : $pollResponse2;
                }
                if ($method === 'GET' && $url === 'https://cdn.example.com/video.mp4') {
                    return $downloadResponse;
                }

                throw new \RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVideoProviderConfig(
            enabled: true,
            model: 'test-model',
            defaultPreset: '',
            pollIntervalSeconds: 0,
            maxAttempts: 5,
            maxPollDurationSeconds: 0,
        ));

        $targetPath = sys_get_temp_dir() . '/replicate_test_video_' . uniqid('', true) . '.mp4';

        try {
            $result = $provider->generateVideo('A mysterious forest', [
                'target_path' => $targetPath,
                'scene_id' => 'scene-42',
                'duration' => 8,
            ]);

            self::assertInstanceOf(GeneratedAssetResult::class, $result);
            self::assertSame($targetPath, $result->path);
            self::assertNull($result->duration);

            self::assertFileExists($targetPath);
            self::assertSame('FAKE-VIDEO-DATA', file_get_contents($targetPath));

            self::assertSame('replicate-video', $result->metadata['provider'] ?? null);
            self::assertSame('succeeded', $result->metadata['provider_status'] ?? null);
            self::assertSame('pred-123', $result->metadata['prediction_id'] ?? null);
            self::assertSame('test-model', $result->metadata['model'] ?? null);
            self::assertNull($result->metadata['replicate_preset'] ?? null);
            self::assertSame('https://cdn.example.com/video.mp4', $result->metadata['remote_output_url'] ?? null);
            self::assertSame('scene-42', $result->metadata['scene_id'] ?? null);
            self::assertSame('A mysterious forest', $result->metadata['prompt'] ?? null);
            self::assertSame(['test_metric' => 1], $result->metadata['metrics'] ?? null);

            self::assertSame(2, $result->metadata['poll_attempts'] ?? null);
            self::assertArrayHasKey('started_at', $result->metadata);
            self::assertArrayHasKey('completed_at', $result->metadata);
            self::assertIsFloat($result->metadata['generation_time_seconds'] ?? null);
            self::assertGreaterThanOrEqual(0.0, $result->metadata['generation_time_seconds']);

            self::assertIsArray($postJson);
            self::assertSame('test-model', $postJson['version']);
            self::assertSame('A mysterious forest', $postJson['input']['prompt']);
            self::assertSame(8, $postJson['input']['duration']);
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }

    public function test_generate_video_failure_when_prediction_fails(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $createResponse = $this->jsonHttpResponse([
            'id' => 'pred-456',
            'status' => 'starting',
        ]);
        $failedPollResponse = $this->jsonHttpResponse([
            'id' => 'pred-456',
            'status' => 'failed',
            'error' => 'Something went wrong',
        ]);

        $httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($createResponse, $failedPollResponse) {
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    return $createResponse;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-456')) {
                    return $failedPollResponse;
                }

                throw new \RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVideoProviderConfig(
            enabled: true,
            model: 'test-model',
            defaultPreset: '',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
            maxPollDurationSeconds: 0,
        ));

        try {
            $provider->generateVideo('A failing prediction');
            self::fail('Expected ReplicatePredictionFailedException');
        } catch (ReplicatePredictionFailedException $e) {
            self::assertSame('pred-456', $e->predictionId());
            self::assertSame('test-model', $e->model());
            self::assertSame('failed', $e->remoteStatus());
            self::assertSame('Something went wrong', $e->remoteError());
        }
    }

    public function test_generate_video_preset_and_replicate_input_shape(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $resolvedVersionId = str_repeat('b', 64);
        $modelMetaResponse = $this->jsonHttpResponse([
            'owner' => 'custom',
            'name' => 'override-model',
            'latest_version' => ['id' => $resolvedVersionId],
        ]);
        $createResponse = $this->jsonHttpResponse(['id' => 'pred-789', 'status' => 'starting']);
        $pollResponse = $this->jsonHttpResponse([
            'id' => 'pred-789',
            'status' => 'succeeded',
            'output' => 'https://cdn.example.com/v2.mp4',
        ]);
        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getStatusCode')->willReturn(200);
        $downloadResponse->method('getContent')->with(false)->willReturn('X');

        $postJson = null;
        $pollCount = 0;
        $httpClient
            ->expects($this->exactly(4))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $opts = []) use (
                &$postJson,
                &$pollCount,
                $modelMetaResponse,
                $createResponse,
                $pollResponse,
                $downloadResponse
            ) {
                if ($method === 'GET' && str_contains($url, '/models/custom/override-model')) {
                    return $modelMetaResponse;
                }
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    $postJson = $opts['json'] ?? null;

                    return $createResponse;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-789')) {
                    ++$pollCount;

                    return $pollResponse;
                }
                if ($method === 'GET' && $url === 'https://cdn.example.com/v2.mp4') {
                    return $downloadResponse;
                }

                throw new \RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVideoProviderConfig(
            enabled: true,
            model: 'fallback/from-config',
            defaultPreset: '',
            pollIntervalSeconds: 0,
            maxAttempts: 5,
            maxPollDurationSeconds: 0,
        ));

        $targetPath = sys_get_temp_dir() . '/replicate_preset_test_' . uniqid('', true) . '.mp4';

        try {
            $result = $provider->generateVideo('Bench prompt', [
                'target_path' => $targetPath,
                'replicate_preset' => ReplicateVideoModelPresets::P_VIDEO_DRAFT,
                'replicate_model' => 'custom/override-model',
                'replicate_input' => ['resolution' => '720p'],
            ]);

            self::assertSame($resolvedVersionId, $postJson['version']);
            self::assertTrue($postJson['input']['draft']);
            self::assertSame('720p', $postJson['input']['resolution']);
            self::assertSame('Bench prompt', $postJson['input']['prompt']);
            self::assertSame('custom/override-model', $result->metadata['model']);
            self::assertSame(ReplicateVideoModelPresets::P_VIDEO_DRAFT, $result->metadata['replicate_preset']);
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }

    public function test_create_prediction_http_error_surfaces_replicate_body_not_missing_id(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $errorResponse = $this->jsonHttpResponse(['detail' => 'Invalid version or input'], 422);

        $httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($errorResponse);

        $provider = $this->makeProvider($httpClient, new ReplicateVideoProviderConfig(
            enabled: true,
            model: 'bare-slug-no-slash',
            defaultPreset: '',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
            maxPollDurationSeconds: 0,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid version or input');
        $this->expectExceptionMessage('HTTP 422');

        $provider->generateVideo('prompt');
    }

    public function test_owner_model_slug_resolves_via_models_api_and_prediction_id_in_metadata(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $resolvedVersion = str_repeat('1', 64);
        $modelMeta = $this->jsonHttpResponse([
            'owner' => 'minimax',
            'name' => 'hailuo-02-fast',
            'latest_version' => ['id' => $resolvedVersion],
        ]);
        $create = $this->jsonHttpResponse(['id' => 'pred-hailuo', 'status' => 'starting']);
        $pollOk = $this->jsonHttpResponse([
            'id' => 'pred-hailuo',
            'status' => 'succeeded',
            'output' => ['https://cdn.example.com/h.mp4'],
        ]);
        $download = $this->createMock(ResponseInterface::class);
        $download->method('getStatusCode')->willReturn(200);
        $download->method('getContent')->with(false)->willReturn('VID');

        $postJson = null;
        $httpClient
            ->expects($this->exactly(4))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $opts = []) use (
                &$postJson,
                $modelMeta,
                $create,
                $pollOk,
                $download,
            ) {
                if ($method === 'GET' && str_contains($url, '/models/minimax/hailuo-02-fast')) {
                    return $modelMeta;
                }
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    $postJson = $opts['json'] ?? null;

                    return $create;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-hailuo')) {
                    return $pollOk;
                }
                if ($method === 'GET' && $url === 'https://cdn.example.com/h.mp4') {
                    return $download;
                }

                throw new \RuntimeException('Unexpected: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVideoProviderConfig(
            enabled: true,
            model: '',
            defaultPreset: ReplicateVideoModelPresets::HAILUO,
            pollIntervalSeconds: 0,
            maxAttempts: 5,
            maxPollDurationSeconds: 0,
        ));

        $targetPath = sys_get_temp_dir() . '/replicate_hailuo_' . uniqid('', true) . '.mp4';

        try {
            $result = $provider->generateVideo('forest mood', ['target_path' => $targetPath]);

            self::assertSame($resolvedVersion, $postJson['version'] ?? null);
            self::assertSame('pred-hailuo', $result->metadata['prediction_id'] ?? null);
            self::assertSame('minimax/hailuo-02-fast', $result->metadata['model'] ?? null);
            self::assertSame(ReplicateVideoModelPresets::HAILUO, $result->metadata['replicate_preset'] ?? null);
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }

    public function test_poll_timeout_exceeded(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $createResponse = $this->jsonHttpResponse(['id' => 'pred-slow', 'status' => 'starting']);
        $processingResponse = $this->jsonHttpResponse([
            'id' => 'pred-slow',
            'status' => 'processing',
        ]);

        $httpClient
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($createResponse, $processingResponse) {
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    return $createResponse;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-slow')) {
                    return $processingResponse;
                }

                throw new \RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVideoProviderConfig(
            enabled: true,
            model: 'm',
            defaultPreset: '',
            pollIntervalSeconds: 1,
            maxAttempts: 50,
            maxPollDurationSeconds: 1,
        ));

        try {
            $provider->generateVideo('Slow job');
            self::fail('Expected ReplicatePredictionFailedException');
        } catch (ReplicatePredictionFailedException $e) {
            self::assertSame('pred-slow', $e->predictionId());
            self::assertSame('poll_timeout', $e->remoteStatus());
        }
    }

    private function makeProvider(HttpClientInterface $httpClient, ReplicateVideoProviderConfig $videoConfig): ReplicateVideoGenerationProvider
    {
        $apiConfig = new ReplicateApiConfig('test-token');
        $replicate = new ReplicateClient($httpClient, $apiConfig, ReplicateTestRateLimiterFactory::create());

        return new ReplicateVideoGenerationProvider(
            $replicate,
            new ReplicateVideoInputMapper(),
            $videoConfig,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonHttpResponse(array $data, int $statusCode = 200): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->with(false)->willReturn(json_encode($data, JSON_THROW_ON_ERROR));

        return $response;
    }
}

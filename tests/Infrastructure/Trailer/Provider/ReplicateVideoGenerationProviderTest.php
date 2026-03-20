<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Provider;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoModelPresets;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoProviderConfig;
use App\Infrastructure\Trailer\Provider\ReplicateVideoGenerationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ReplicateVideoGenerationProviderTest extends TestCase
{
    public function test_generate_video_happy_path_with_mocked_http(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $createResponse = $this->createMock(ResponseInterface::class);
        $pollResponse1 = $this->createMock(ResponseInterface::class);
        $pollResponse2 = $this->createMock(ResponseInterface::class);
        $downloadResponse = $this->createMock(ResponseInterface::class);

        $createResponse
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'id' => 'pred-123',
                'status' => 'starting',
            ]);

        $pollResponse1
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'id' => 'pred-123',
                'status' => 'processing',
            ]);

        $pollResponse2
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'id' => 'pred-123',
                'status' => 'succeeded',
                'output' => ['https://cdn.example.com/video.mp4'],
                'metrics' => ['test_metric' => 1],
            ]);

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

        $config = new ReplicateVideoProviderConfig(
            enabled: true,
            apiToken: 'test-token',
            model: 'test-model',
            pollIntervalSeconds: 0,
            maxAttempts: 5,
        );

        $provider = new ReplicateVideoGenerationProvider($httpClient, $config);

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
            self::assertSame('https://cdn.example.com/video.mp4', $result->metadata['remote_output_url'] ?? null);
            self::assertSame('scene-42', $result->metadata['scene_id'] ?? null);
            self::assertSame('A mysterious forest', $result->metadata['prompt'] ?? null);
            self::assertSame(['test_metric' => 1], $result->metadata['metrics'] ?? null);

            self::assertSame(2, $result->metadata['poll_attempts'] ?? null);
            self::assertArrayHasKey('started_at', $result->metadata);
            self::assertArrayHasKey('completed_at', $result->metadata);

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

        $createResponse = $this->createMock(ResponseInterface::class);
        $failedPollResponse = $this->createMock(ResponseInterface::class);

        $createResponse
            ->method('toArray')
            ->with(false)
            ->willReturn([
                'id' => 'pred-456',
                'status' => 'starting',
            ]);

        $failedPollResponse
            ->method('toArray')
            ->with(false)
            ->willReturn([
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

        $config = new ReplicateVideoProviderConfig(
            enabled: true,
            apiToken: 'test-token',
            model: 'test-model',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
        );

        $provider = new ReplicateVideoGenerationProvider($httpClient, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Replicate prediction pred-456 failed with status "failed": Something went wrong');

        $provider->generateVideo('A failing prediction');
    }

    public function test_generate_video_preset_and_replicate_input_shape(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $createResponse = $this->createMock(ResponseInterface::class);
        $pollResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse = $this->createMock(ResponseInterface::class);

        $createResponse->method('toArray')->with(false)->willReturn(['id' => 'pred-789', 'status' => 'starting']);
        $pollResponse->method('toArray')->with(false)->willReturn([
            'id' => 'pred-789',
            'status' => 'succeeded',
            'output' => 'https://cdn.example.com/v2.mp4',
        ]);
        $downloadResponse->method('getStatusCode')->willReturn(200);
        $downloadResponse->method('getContent')->with(false)->willReturn('X');

        $postJson = null;
        $pollCount = 0;
        $httpClient
            ->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $opts = []) use (
                &$postJson,
                &$pollCount,
                $createResponse,
                $pollResponse,
                $downloadResponse
            ) {
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

        $config = new ReplicateVideoProviderConfig(
            enabled: true,
            apiToken: 't',
            model: 'fallback/from-config',
            pollIntervalSeconds: 0,
            maxAttempts: 5,
        );

        $provider = new ReplicateVideoGenerationProvider($httpClient, $config);

        $targetPath = sys_get_temp_dir() . '/replicate_preset_test_' . uniqid('', true) . '.mp4';

        try {
            $result = $provider->generateVideo('Bench prompt', [
                'target_path' => $targetPath,
                'replicate_preset' => ReplicateVideoModelPresets::P_VIDEO_DRAFT,
                'replicate_model' => 'custom/override-model',
                'replicate_input' => ['resolution' => '720p'],
            ]);

            self::assertSame('custom/override-model', $postJson['version']);
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
}


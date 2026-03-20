<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Provider;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateApiConfig;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateClient;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicatePredictionFailedException;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVoiceProviderConfig;
use App\Infrastructure\Trailer\Provider\ReplicateVoiceGenerationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ReplicateVoiceGenerationProviderTest extends TestCase
{
    public function test_generate_voice_happy_path_with_mocked_http(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $resolvedVersionId = str_repeat('c', 64);
        $modelMetaResponse = $this->jsonHttpResponse([
            'owner' => 'minimax',
            'name' => 'speech-2.6-turbo',
            'latest_version' => ['id' => $resolvedVersionId],
        ]);
        $createResponse = $this->jsonHttpResponse([
            'id' => 'pred-voice-1',
            'status' => 'starting',
        ]);
        $pollResponse1 = $this->jsonHttpResponse([
            'id' => 'pred-voice-1',
            'status' => 'processing',
        ]);
        $pollResponse2 = $this->jsonHttpResponse([
            'id' => 'pred-voice-1',
            'status' => 'succeeded',
            'output' => 'https://cdn.example.com/voice.mp3',
            'metrics' => ['duration' => 2.5],
        ]);
        $downloadResponse = $this->createMock(ResponseInterface::class);

        $downloadResponse
            ->method('getStatusCode')
            ->willReturn(200);

        $downloadResponse
            ->method('getContent')
            ->with(false)
            ->willReturn('FAKE-MP3-BYTES');

        $postJson = null;
        $pollCount = 0;
        $httpClient
            ->expects($this->exactly(5))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $opts = []) use (
                &$postJson,
                &$pollCount,
                $modelMetaResponse,
                $createResponse,
                $pollResponse1,
                $pollResponse2,
                $downloadResponse
            ) {
                if ($method === 'GET' && str_contains($url, '/models/minimax/speech-2.6-turbo')) {
                    return $modelMetaResponse;
                }
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    $postJson = $opts['json'] ?? null;

                    return $createResponse;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-voice-1')) {
                    ++$pollCount;

                    return $pollCount === 1 ? $pollResponse1 : $pollResponse2;
                }
                if ($method === 'GET' && $url === 'https://cdn.example.com/voice.mp3') {
                    return $downloadResponse;
                }

                throw new \RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVoiceProviderConfig(
            enabled: true,
            model: 'minimax/speech-2.6-turbo',
            voiceId: 'Wise_Woman',
            audioFormat: 'mp3',
            pollIntervalSeconds: 0,
            maxAttempts: 5,
            maxPollDurationSeconds: 0,
        ));

        $targetPath = sys_get_temp_dir() . '/replicate_test_voice_' . uniqid('', true) . '.mp3';

        try {
            $result = $provider->generateVoice('Hello from the forest', [
                'target_path' => $targetPath,
                'scene_id' => 'scene-1',
            ]);

            self::assertInstanceOf(GeneratedAssetResult::class, $result);
            self::assertSame($targetPath, $result->path);
            self::assertSame(2.5, $result->duration);

            self::assertFileExists($targetPath);
            self::assertSame('FAKE-MP3-BYTES', file_get_contents($targetPath));

            self::assertSame('replicate-voice', $result->metadata['provider'] ?? null);
            self::assertSame('succeeded', $result->metadata['provider_status'] ?? null);
            self::assertSame('pred-voice-1', $result->metadata['prediction_id'] ?? null);
            self::assertSame('minimax/speech-2.6-turbo', $result->metadata['model'] ?? null);
            self::assertSame('https://cdn.example.com/voice.mp3', $result->metadata['remote_output_url'] ?? null);
            self::assertSame('scene-1', $result->metadata['scene_id'] ?? null);
            self::assertSame('Hello from the forest', $result->metadata['narration'] ?? null);
            self::assertSame('Wise_Woman', $result->metadata['voice_id'] ?? null);
            self::assertSame($resolvedVersionId, $result->metadata['replicate_version'] ?? null);
            self::assertSame('mp3', $result->metadata['audio_format'] ?? null);

            self::assertIsArray($postJson);
            self::assertSame($resolvedVersionId, $postJson['version']);
            self::assertSame('Hello from the forest', $postJson['input']['text']);
            self::assertSame('Wise_Woman', $postJson['input']['voice_id']);
            self::assertSame('mp3', $postJson['input']['audio_format']);
            self::assertSame(128000, $postJson['input']['bitrate']);
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }

    public function test_generate_voice_failure_when_prediction_fails(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $createResponse = $this->jsonHttpResponse([
            'id' => 'pred-bad',
            'status' => 'starting',
        ]);
        $failedPollResponse = $this->jsonHttpResponse([
            'id' => 'pred-bad',
            'status' => 'failed',
            'error' => 'TTS error',
        ]);

        $httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($createResponse, $failedPollResponse) {
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    return $createResponse;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-bad')) {
                    return $failedPollResponse;
                }

                throw new \RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVoiceProviderConfig(
            enabled: true,
            model: str_repeat('d', 64),
            voiceId: 'Wise_Woman',
            audioFormat: 'mp3',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
            maxPollDurationSeconds: 0,
        ));

        try {
            $provider->generateVoice('Nope');
            self::fail('Expected ReplicatePredictionFailedException');
        } catch (ReplicatePredictionFailedException $e) {
            self::assertSame('pred-bad', $e->predictionId());
            self::assertSame(str_repeat('d', 64), $e->model());
            self::assertSame('failed', $e->remoteStatus());
            self::assertSame('TTS error', $e->remoteError());
        }
    }

    public function test_empty_voice_id_rejected_before_http(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $provider = $this->makeProvider($httpClient, new ReplicateVoiceProviderConfig(
            enabled: true,
            model: str_repeat('a', 64),
            voiceId: '',
            audioFormat: 'mp3',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
            maxPollDurationSeconds: 0,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('voice_id is empty');

        $provider->generateVoice('Hello');
    }

    public function test_create_prediction_422_surfaces_replicate_detail(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $modelMeta = $this->jsonHttpResponse([
            'latest_version' => ['id' => str_repeat('9', 64)],
        ]);
        $errorCreate = $this->jsonHttpResponse(['detail' => 'Input validation failed'], 422);

        $httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($modelMeta, $errorCreate) {
                if ($method === 'GET' && str_contains($url, '/models/minimax/speech-2.6-turbo')) {
                    return $modelMeta;
                }
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    return $errorCreate;
                }

                throw new \RuntimeException('Unexpected: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVoiceProviderConfig(
            enabled: true,
            model: 'minimax/speech-2.6-turbo',
            voiceId: 'Wise_Woman',
            audioFormat: 'mp3',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
            maxPollDurationSeconds: 0,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Input validation failed');
        $this->expectExceptionMessage('HTTP 422');

        $provider->generateVoice('Hi');
    }

    public function test_placeholder_voice_id_fails_before_http(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('request');

        $provider = $this->makeProvider($httpClient, new ReplicateVoiceProviderConfig(
            enabled: true,
            model: 'minimax/speech-2.6-turbo',
            voiceId: 'TON_VOICE_ID',
            audioFormat: 'mp3',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
            maxPollDurationSeconds: 0,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TRAILER_VOICE_REPLICATE_VOICE_ID');

        $provider->generateVoice('Hello');
    }

    public function test_remote_voice_id_error_includes_config_hint(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);

        $createResponse = $this->jsonHttpResponse([
            'id' => 'pred-voice',
            'status' => 'starting',
        ]);
        $failedPollResponse = $this->jsonHttpResponse([
            'id' => 'pred-voice',
            'status' => 'failed',
            'error' => 'Speech generation failed: voice id not exist',
        ]);

        $httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use ($createResponse, $failedPollResponse) {
                if ($method === 'POST' && str_contains($url, '/predictions')) {
                    return $createResponse;
                }
                if ($method === 'GET' && str_contains($url, '/predictions/pred-voice')) {
                    return $failedPollResponse;
                }

                throw new \RuntimeException('Unexpected HTTP request: ' . $method . ' ' . $url);
            });

        $provider = $this->makeProvider($httpClient, new ReplicateVoiceProviderConfig(
            enabled: true,
            model: str_repeat('e', 64),
            voiceId: 'NotARealVoice',
            audioFormat: 'mp3',
            pollIntervalSeconds: 0,
            maxAttempts: 3,
            maxPollDurationSeconds: 0,
        ));

        try {
            $provider->generateVoice('Hi');
            self::fail('Expected ReplicatePredictionFailedException');
        } catch (ReplicatePredictionFailedException $e) {
            self::assertStringContainsString('voice id not exist', $e->getMessage());
            self::assertStringContainsString('TRAILER_VOICE_REPLICATE_VOICE_ID', $e->getMessage());
            self::assertStringContainsString('NotARealVoice', $e->getMessage());
        }
    }

    private function makeProvider(HttpClientInterface $httpClient, ReplicateVoiceProviderConfig $voiceConfig): ReplicateVoiceGenerationProvider
    {
        $apiConfig = new ReplicateApiConfig('test-token');
        $replicate = new ReplicateClient($httpClient, $apiConfig);

        return new ReplicateVoiceGenerationProvider($replicate, $voiceConfig);
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

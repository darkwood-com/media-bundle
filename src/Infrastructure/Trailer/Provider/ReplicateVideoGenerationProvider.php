<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateClient;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicatePredictionFailedException;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoInputMapper;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoModelPresets;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoProviderConfig;

/**
 * Real video generation provider backed by Replicate's HTTP API.
 *
 * HTTP is delegated to {@see ReplicateClient}; this class only orchestrates
 * presets, input mapping, polling, and local download paths.
 */
final class ReplicateVideoGenerationProvider implements VideoGenerationProviderInterface
{
    private const PROVIDER_NAME = 'replicate-video';

    public function __construct(
        private readonly ReplicateClient $replicateClient,
        private readonly ReplicateVideoInputMapper $videoInputMapper,
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

        if (!$this->replicateClient->hasApiToken()) {
            throw new \RuntimeException('Replicate video provider is misconfigured (missing API token).');
        }

        $presetKey = $this->resolvePresetKey($options);
        $resolvedModel = $this->resolveModelIdentifier($options, $presetKey);
        if ($resolvedModel === '') {
            throw new \RuntimeException(
                'Replicate video provider: set TRAILER_VIDEO_REPLICATE_MODEL or TRAILER_VIDEO_REPLICATE_DEFAULT_PRESET, or pass replicate_preset / replicate_model in options.'
            );
        }

        $presetInput = $this->presetBaseInput($presetKey);
        $targetPath = $options['target_path'] ?? $this->defaultPath($prompt, 'mp4');
        $sceneId = $options['scene_id'] ?? null;

        $wallClockStart = microtime(true);
        $startedAt = new \DateTimeImmutable('now');
        $startPoll = $wallClockStart;

        $input = $this->videoInputMapper->buildInput($resolvedModel, $presetInput, $prompt, $options);
        $version = $this->replicateClient->resolvePredictionVersion($resolvedModel);
        $initialPrediction = $this->replicateClient->createPrediction([
            'version' => $version,
            'input' => $input,
        ]);

        $predictionId = (string) ($initialPrediction['id'] ?? '');

        if ($predictionId === '') {
            $hint = $this->summarizePredictionBodyForMissingId($initialPrediction);
            throw new \RuntimeException(
                'Replicate video provider did not return a prediction id after a successful HTTP response.'
                . ($hint !== null ? ' ' . $hint : '')
            );
        }

        [$finalPrediction, $attempts] = $this->waitForPrediction(
            $predictionId,
            $startPoll,
            $resolvedModel,
            $presetKey,
        );

        $status = (string) ($finalPrediction['status'] ?? 'unknown');

        if (!in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
            throw new ReplicatePredictionFailedException(
                sprintf(
                    'Replicate prediction %s ended in unexpected status "%s".',
                    $predictionId,
                    $status
                ),
                $predictionId,
                $resolvedModel,
                $status,
                null,
                $presetKey,
            );
        }

        if ($status !== 'succeeded') {
            throw ReplicatePredictionFailedException::terminalPredictionFailure(
                $predictionId,
                $resolvedModel,
                $status,
                $finalPrediction['error'] ?? null,
                $presetKey,
            );
        }

        $output = $finalPrediction['output'] ?? null;
        $outputUrl = $this->replicateClient->extractFirstOutputUrl($output);

        if ($outputUrl === null) {
            throw new \RuntimeException(sprintf(
                'Replicate prediction %s succeeded but did not return a usable output URL.',
                $predictionId
            ));
        }

        $this->replicateClient->downloadToPath($outputUrl, $targetPath);

        $completedAt = new \DateTimeImmutable('now');

        $metadata = [
            'provider' => self::PROVIDER_NAME,
            'provider_status' => $status,
            'prediction_id' => $predictionId,
            'model' => $resolvedModel,
            'replicate_preset' => $presetKey,
            'remote_output_url' => $outputUrl,
            'poll_attempts' => $attempts,
            'started_at' => $startedAt->format(\DateTimeInterface::ATOM),
            'completed_at' => $completedAt->format(\DateTimeInterface::ATOM),
            'generation_time_seconds' => round(microtime(true) - $wallClockStart, 3),
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
     */
    private function resolvePresetKey(array $options): ?string
    {
        $fromCall = $options['replicate_preset'] ?? null;
        if (is_string($fromCall) && $fromCall !== '') {
            return $fromCall;
        }

        $fromConfig = $this->config->defaultPreset;
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presetBaseInput(?string $presetKey): array
    {
        if ($presetKey === null) {
            return [];
        }

        return ReplicateVideoModelPresets::resolve($presetKey)['input'];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveModelIdentifier(array $options, ?string $presetKey): string
    {
        $model = $this->config->model;

        if ($presetKey !== null) {
            $resolved = ReplicateVideoModelPresets::resolve($presetKey);
            $model = $resolved['model'];
        }

        $override = $options['replicate_model'] ?? null;
        if (is_string($override) && $override !== '') {
            $model = $override;
        }

        return $model;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function waitForPrediction(
        string $predictionId,
        float $pollStartedAt,
        string $model,
        ?string $replicatePreset,
    ): array {
        $attempts = 0;
        $maxDuration = $this->config->maxPollDurationSeconds;

        while (true) {
            ++$attempts;

            if ($maxDuration > 0 && (microtime(true) - $pollStartedAt) >= $maxDuration) {
                throw new ReplicatePredictionFailedException(
                    sprintf(
                        'Replicate prediction %s exceeded poll timeout (%d seconds) after %d attempt(s).',
                        $predictionId,
                        $maxDuration,
                        $attempts
                    ),
                    $predictionId,
                    $model,
                    'poll_timeout',
                    sprintf('timeout_seconds=%d', $maxDuration),
                    $replicatePreset,
                );
            }

            $prediction = $this->replicateClient->getPrediction($predictionId);
            $status = (string) ($prediction['status'] ?? '');

            if (in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
                return [$prediction, $attempts];
            }

            if ($attempts >= $this->config->maxAttempts) {
                throw new ReplicatePredictionFailedException(
                    sprintf(
                        'Replicate prediction %s did not reach a terminal state after %d attempts (last status: "%s").',
                        $predictionId,
                        $attempts,
                        $status
                    ),
                    $predictionId,
                    $model,
                    'poll_exhausted',
                    $status !== '' ? 'last_status=' . $status : null,
                    $replicatePreset,
                );
            }

            $interval = $this->config->pollIntervalSeconds;
            if ($interval > 0) {
                sleep($interval);
            }
        }
    }

    private function defaultPath(string $prompt, string $ext): string
    {
        $hash = substr(hash('xxh128', $prompt), 0, 16);

        return sys_get_temp_dir() . '/replicate_video_' . $hash . '.' . $ext;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function summarizePredictionBodyForMissingId(array $body): ?string
    {
        if ($body === []) {
            return 'Response body was empty or not JSON.';
        }

        foreach (['detail', 'message', 'title'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                return 'API payload: ' . $body[$key];
            }
        }

        $json = json_encode($body);

        return $json !== false ? 'Response JSON: ' . $json : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VoiceGenerationProviderInterface;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateClient;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicatePredictionFailedException;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVoiceProviderConfig;

/**
 * Text-to-speech via Replicate (MiniMax Speech 2.6 Turbo and compatible schemas).
 */
final class ReplicateVoiceGenerationProvider implements VoiceGenerationProviderInterface
{
    private const PROVIDER_NAME = 'replicate-voice';

    public function __construct(
        private readonly ReplicateClient $replicateClient,
        private readonly ReplicateVoiceProviderConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generateVoice(string $text, array $options = []): GeneratedAssetResult
    {
        if (!$this->config->enabled) {
            throw new \RuntimeException('Replicate voice provider is disabled by configuration.');
        }

        if (!$this->replicateClient->hasApiToken()) {
            throw new \RuntimeException('Replicate voice provider is misconfigured (missing API token).');
        }

        $model = $this->resolveModel($options);
        if ($model === '') {
            throw new \RuntimeException(
                'Replicate voice provider: set TRAILER_VOICE_REPLICATE_MODEL or pass replicate_model in options.'
            );
        }

        $targetPath = $options['target_path'] ?? $this->defaultPath($text, $this->resolveFileExtension($options));
        $sceneId = $options['scene_id'] ?? null;

        $wallClockStart = microtime(true);
        $startedAt = new \DateTimeImmutable('now');
        $startPoll = $wallClockStart;

        $input = $this->buildInput($text, $options);
        $this->assertVoiceIdIsConfigured((string) ($input['voice_id'] ?? ''));

        $version = $this->replicateClient->resolvePredictionVersion($model);
        $initialPrediction = $this->replicateClient->createPrediction([
            'version' => $version,
            'input' => $input,
        ]);

        $predictionId = (string) ($initialPrediction['id'] ?? '');
        if ($predictionId === '') {
            $hint = $this->summarizePredictionBodyForMissingId($initialPrediction);
            throw new \RuntimeException(
                'Replicate voice provider did not return a prediction id after a successful HTTP response.'
                . ($hint !== null ? ' ' . $hint : '')
            );
        }

        [$finalPrediction, $attempts] = $this->waitForPrediction($predictionId, $startPoll, $model);

        $status = (string) ($finalPrediction['status'] ?? 'unknown');
        if (!in_array($status, ['succeeded', 'failed', 'canceled'], true)) {
            throw new ReplicatePredictionFailedException(
                sprintf(
                    'Replicate prediction %s ended in unexpected status "%s".',
                    $predictionId,
                    $status
                ),
                $predictionId,
                $model,
                $status,
                null,
                null,
            );
        }

        if ($status !== 'succeeded') {
            $remoteError = $finalPrediction['error'] ?? null;
            $remoteError = $this->enrichRemoteErrorForVoiceMisconfiguration(
                $remoteError,
                (string) ($input['voice_id'] ?? '')
            );
            throw ReplicatePredictionFailedException::terminalPredictionFailure(
                $predictionId,
                $model,
                $status,
                $remoteError,
                null,
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
        $audioFormat = $input['audio_format'] ?? $this->config->audioFormat;

        $metadata = [
            'provider' => self::PROVIDER_NAME,
            'provider_status' => $status,
            'prediction_id' => $predictionId,
            'model' => $model,
            'replicate_version' => $version,
            'remote_output_url' => $outputUrl,
            'poll_attempts' => $attempts,
            'started_at' => $startedAt->format(\DateTimeInterface::ATOM),
            'completed_at' => $completedAt->format(\DateTimeInterface::ATOM),
            'generation_time_seconds' => round(microtime(true) - $wallClockStart, 3),
            'scene_id' => $sceneId,
            'narration' => $text,
            'voice_id' => $input['voice_id'] ?? null,
            'audio_format' => $audioFormat,
        ];

        if (isset($finalPrediction['metrics']) && is_array($finalPrediction['metrics'])) {
            $metadata['metrics'] = $finalPrediction['metrics'];
            $duration = $this->extractDurationSeconds($finalPrediction['metrics']);
            if ($duration !== null) {
                return new GeneratedAssetResult(
                    path: $targetPath,
                    duration: $duration,
                    metadata: $metadata,
                );
            }
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
    private function buildInput(string $text, array $options): array
    {
        $voiceId = $this->stringOption($options, 'voice_id', $this->config->voiceId);
        $audioFormat = strtolower($this->stringOption($options, 'audio_format', $this->config->audioFormat));

        $input = [
            'text' => $text,
            'voice_id' => $voiceId,
            'audio_format' => $audioFormat,
            'speed' => $this->floatOption($options, 'speed', 1.0),
            'emotion' => $this->stringOption($options, 'emotion', 'auto'),
            'channel' => $this->stringOption($options, 'channel', 'mono'),
            'sample_rate' => $this->intOption($options, 'sample_rate', 32000),
            'pitch' => $this->intOption($options, 'pitch', 0),
            'volume' => $this->floatOption($options, 'volume', 1.0),
        ];

        if ($audioFormat === 'mp3') {
            $input['bitrate'] = $this->intOption($options, 'bitrate', 128000);
        }

        if (array_key_exists('english_normalization', $options)) {
            $input['english_normalization'] = (bool) $options['english_normalization'];
        }

        if (array_key_exists('subtitle_enable', $options)) {
            $input['subtitle_enable'] = (bool) $options['subtitle_enable'];
        }

        $languageBoost = $options['language_boost'] ?? null;
        if (is_string($languageBoost) && $languageBoost !== '') {
            $input['language_boost'] = $languageBoost;
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveModel(array $options): string
    {
        $override = $options['replicate_model'] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return $this->config->model;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveFileExtension(array $options): string
    {
        $fmt = strtolower($this->stringOption($options, 'audio_format', $this->config->audioFormat));

        return match ($fmt) {
            'wav' => 'wav',
            'flac' => 'flac',
            'pcm' => 'pcm',
            default => 'mp3',
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key, string $default): string
    {
        $v = $options[$key] ?? $default;

        return is_string($v) && $v !== '' ? $v : $default;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function intOption(array $options, string $key, int $default): int
    {
        $v = $options[$key] ?? $default;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function floatOption(array $options, string $key, float $default): float
    {
        $v = $options[$key] ?? $default;
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function extractDurationSeconds(array $metrics): ?float
    {
        foreach (['audio_duration', 'duration', 'duration_seconds'] as $k) {
            if (!isset($metrics[$k])) {
                continue;
            }
            $v = $metrics[$k];
            if (is_int($v) || is_float($v)) {
                return (float) $v;
            }
            if (is_string($v) && is_numeric($v)) {
                return (float) $v;
            }
        }

        return null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function waitForPrediction(string $predictionId, float $pollStartedAt, string $model): array
    {
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
                    null,
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
                    null,
                );
            }

            $interval = $this->config->pollIntervalSeconds;
            if ($interval > 0) {
                sleep($interval);
            }
        }
    }

    private function defaultPath(string $text, string $ext): string
    {
        $hash = substr(hash('xxh128', $text), 0, 16);

        return sys_get_temp_dir() . '/replicate_voice_' . $hash . '.' . $ext;
    }

    /**
     * MiniMax Speech on Replicate requires a real catalogue voice_id; placeholders break at runtime with opaque API errors.
     */
    private function assertVoiceIdIsConfigured(string $voiceId): void
    {
        if (trim($voiceId) === '') {
            throw new \RuntimeException(
                'Replicate voice provider: voice_id is empty. Set TRAILER_VOICE_REPLICATE_VOICE_ID (or pass voice_id in options) '
                . 'to a valid MiniMax voice for minimax/speech-2.6-turbo — see the model’s API tab on Replicate (example: Wise_Woman).'
            );
        }

        $lower = strtolower($voiceId);
        $placeholderNeedles = [
            'placeholder',
            'your_voice',
            'change_me',
            'changeme',
            'todo',
            'example_voice',
            'ton_voice',
            'my_voice_id',
            'set_me',
            'fixme',
        ];
        foreach ($placeholderNeedles as $needle) {
            if (str_contains($lower, $needle)) {
                throw new \RuntimeException(sprintf(
                    'Replicate voice provider: voice_id "%s" looks like a placeholder. '
                    . 'Set TRAILER_VOICE_REPLICATE_VOICE_ID to a real MiniMax voice id from the Replicate model page (e.g. Wise_Woman).',
                    $voiceId
                ));
            }
        }

        if (preg_match('/_id$/i', $voiceId) === 1 && !preg_match('/[a-z]/', $voiceId)) {
            throw new \RuntimeException(sprintf(
                'Replicate voice provider: voice_id "%s" looks like a template (all capitals with an _ID suffix). '
                . 'Replace TRAILER_VOICE_REPLICATE_VOICE_ID with a valid MiniMax voice from Replicate (e.g. Wise_Woman).',
                $voiceId
            ));
        }
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

    private function enrichRemoteErrorForVoiceMisconfiguration(mixed $remoteError, string $configuredVoiceId): mixed
    {
        $asString = is_string($remoteError) ? $remoteError : (is_scalar($remoteError) ? (string) $remoteError : null);
        if ($asString === null || $asString === '') {
            return $remoteError;
        }

        if (!preg_match('/voice\s*id|voice_id|invalid\s*voice|not\s*exist/i', $asString)) {
            return $remoteError;
        }

        return sprintf(
            '%s — Check TRAILER_VOICE_REPLICATE_VOICE_ID (currently "%s") matches a MiniMax voice id from the minimax/speech-2.6-turbo model on Replicate.',
            $asString,
            $configuredVoiceId
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VoiceGenerationProviderInterface;

final class FakeVoiceGenerationProvider implements VoiceGenerationProviderInterface
{
    private const PROVIDER_NAME = 'fake-voice';

    public function generateVoice(string $text, array $options = []): GeneratedAssetResult
    {
        $targetPath = $options['target_path'] ?? $this->defaultPath($text, 'mp3');
        $sceneId = $options['scene_id'] ?? null;
        $timestamp = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);

        $dir = \dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        // Minimal valid MP3 (one silent frame) so downstream concat/play works
        $content = $this->minimalMp3();
        file_put_contents($targetPath, $content);

        $metadata = [
            'provider' => self::PROVIDER_NAME,
            'generated_at' => $timestamp,
            'scene_id' => $sceneId,
            'narration' => $text,
        ];

        $this->mergeRealAttemptHints($metadata, $options);

        return new GeneratedAssetResult(
            path: $targetPath,
            duration: 0.0,
            metadata: $metadata,
        );
    }

    private function defaultPath(string $text, string $ext): string
    {
        $hash = substr(hash('xxh128', $text), 0, 16);
        return sys_get_temp_dir() . '/fake_voice_' . $hash . '.' . $ext;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $options
     */
    private function mergeRealAttemptHints(array &$metadata, array $options): void
    {
        if (($options['fallback_from'] ?? null) === 'real') {
            $metadata['fallback_from'] = 'real';
        }

        foreach (
            [
                'real_attempt_prediction_id',
                'real_attempt_provider_model',
                'real_attempt_remote_status',
                'real_attempt_error_message',
            ] as $key
        ) {
            $v = $options[$key] ?? null;
            if (is_string($v) && $v !== '') {
                $metadata[$key] = $v;
            }
        }
    }

    /** @return string Minimal deterministic placeholder (valid MP3-like header + padding for pipeline) */
    private function minimalMp3(): string
    {
        $header = hex2bin('fff32000'); // sync + minimal header
        return $header . str_repeat("\0", 413);
    }
}

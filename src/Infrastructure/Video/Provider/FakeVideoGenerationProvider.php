<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Provider;

use App\Application\Video\DTO\GeneratedAssetResult;
use App\Application\Video\Port\VideoGenerationProviderInterface;
use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoModelPresets;

final class FakeVideoGenerationProvider implements VideoGenerationProviderInterface
{
    private const PROVIDER_NAME = 'fake-video';
    private const FALLBACK_MP4_BASE64 = 'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAARTbW9vdgAAAGxtdmhkAAAAAAAAAAAAAAAAAAAD6AAAA+gAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAA350cmFrAAAAXHRraGQAAAADAAAAAAAAAAAAAAABAAAAAAAAA+gAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAABQAAAALQAAAAAAAkZWR0cwAAABxlbHN0AAAAAAAAAAEAAAPoAAAEAAABAAAAAAL2bWRpYQAAACBtZGhkAAAAAAAAAAAAAAAAAAAwAAAAMABVxAAAAAAALWhkbHIAAAAAAAAAAHZpZGUAAAAAAAAAAAAAAABWaWRlb0hhbmRsZXIAAAACoW1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAAmFzdGJsAAAAwXN0c2QAAAAAAAAAAQAAALFhdmMxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAABQAC0ABIAAAASAAAAAAAAAABFUxhdmM2MS4xOS4xMDEgbGlieDI2NAAAAAAAAAAAAAAAGP//AAAAN2F2Y0MBZAAf/+EAGmdkAB+s2UBQBbsBEAAAAwAQAAADAwDxgxlgAQAGaOvjyyLA/fj4AAAAABBwYXNwAAAAAQAAAAEAAAAUYnRydAAAAAAAADiwAAAAAAAAABhzdHRzAAAAAAAAAAEAAAAYAAACAAAAABRzdHNzAAAAAAAAAAEAAAABAAAAyGN0dHMAAAAAAAAAFwAAAAEAAAQAAAAAAQAACgAAAAABAAAEAAAAAAEAAAAAAAAAAQAAAgAAAAABAAAKAAAAAAEAAAQAAAAAAQAAAAAAAAABAAACAAAAAAEAAAoAAAAAAQAABAAAAAABAAAAAAAAAAEAAAIAAAAAAQAACgAAAAABAAAEAAAAAAEAAAAAAAAAAQAAAgAAAAABAAAKAAAAAAEAAAQAAAAAAQAAAAAAAAABAAACAAAAAAEAAAgAAAAAAgAAAgAAAAAcc3RzYwAAAAAAAAABAAAAAQAAABgAAAABAAAAdHN0c3oAAAAAAAAAAAAAABgAAAOKAAAAKAAAACUAAAAlAAAAJQAAAC4AAAAnAAAAJQAAACUAAAAuAAAAJwAAACUAAAAlAAAALgAAACcAAAAlAAAAJQAAAC4AAAAnAAAAJQAAACUAAAAtAAAAJwAAACUAAAAUc3RjbwAAAAAAAAABAAAEgwAAAGF1ZHRhAAAAWW1ldGEAAAAAAAAAIWhkbHIAAAAAAAAAAG1kaXJhcHBsAAAAAAAAAAAAAAAALGlsc3QAAAAkqXRvbwAAABxkYXRhAAAAAQAAAABMYXZmNjEuNy4xMDAAAAAIZnJlZQAABx5tZGF0AAACrwYF//+r3EXpvebZSLeWLNgg2SPu73gyNjQgLSBjb3JlIDE2NCByMzEwOCAzMWUxOWY5IC0gSC4yNjQvTVBFRy00IEFWQyBjb2RlYyAtIENvcHlsZWZ0IDIwMDMtMjAyMyAtIGh0dHA6Ly93d3cudmlkZW9sYW4ub3JnL3gyNjQuaHRtbCAtIG9wdGlvbnM6IGNhYmFjPTEgcmVmPTMgZGVibG9jaz0xOjA6MCBhbmFseXNlPTB4MzoweDExMyBtZT1oZXggc3VibWU9NyBwc3k9MSBwc3lfcmQ9MS4wMDowLjAwIG1peGVkX3JlZj0xIG1lX3JhbmdlPTE2IGNocm9tYV9tZT0xIHRyZWxsaXM9MSA4eDhkY3Q9MSBjcW09MCBkZWFkem9uZT0yMSwxMSBmYXN0X3Bza2lwPTEgY2hyb21hX3FwX29mZnNldD0tMiB0aHJlYWRzPTE1IGxvb2thaGVhZF90aHJlYWRzPTIgc2xpY2VkX3RocmVhZHM9MCBucj0wIGRlY2ltYXRlPTEgaW50ZXJsYWNlZD0wIGJsdXJheV9jb21wYXQ9MCBjb25zdHJhaW5lZF9pbnRyYT0wIGJmcmFtZXM9MyBiX3B5cmFtaWQ9MiBiX2FkYXB0PTEgYl9iaWFzPTAgZGlyZWN0PTEgd2VpZ2h0Yj0xIG9wZW5fZ29wPTAgd2VpZ2h0cD0yIGtleWludD0yNTAga2V5aW50X21pbj0yNCBzY2VuZWN1dD00MCBpbnRyYV9yZWZyZXNoPTAgcmNfbG9va2FoZWFkPTQwIHJjPWNyZiBtYnRyZWU9MSBjcmY9MjMuMCBxY29tcD0wLjYwIHFwbWluPTAgcXBtYXg9NjkgcXBzdGVwPTQgaXBfcmF0aW89MS40MCBhcT0xOjEuMDAAgAAAANNliIQAO//+906/AptUwioDklcK9sqkJlm5UmsB8qYAAAMAAAMAAAMAAAMACltqKIVp9Mm9NAAAAwAAAwF1AAFVAAImAATUAA1AADJAAOIAA/gAEyAAhoAD7AAYoADrAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAAADAANnAAAAJEGaJGxDv/6plgAAAwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAYMAAAACFBnkJ4hf8AAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAHHEAAAAhAZ5hdEK/AAADAAADAAADAAADAAADAAADAAADAAADACbgAAAAIQGeY2pCvwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAm4QAAACpBmmhJqEFomUwId//+qZYAAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAGDEAAAAjQZ6GRREsL/8AAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAHHEAAAAhAZ6ldEK/AAADAAADAAADAAADAAADAAADAAADAAADACbhAAAAIQGep2pCvwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAm4AAAACpBmqxJqEFsmUwId//+qZYAAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAGDAAAAAjQZ7KRRUsL/8AAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAHHEAAAAhAZ7pdEK/AAADAAADAAADAAADAAADAAADAAADAAADACbgAAAAIQGe62pCvwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAm4AAAACpBmvBJqEFsmUwIb//+p4QAAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAMCEAAAAjQZ8ORRUsL/8AAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAHHEAAAAhAZ8tdEK/AAADAAADAAADAAADAAADAAADAAADAAADACbhAAAAIQGfL2pCvwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAm4AAAACpBmzRJqEFsmUwIZ//+nhAAAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAu4AAAAAjQZ9SRRUsL/8AAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAHHEAAAAhAZ9xdEK/AAADAAADAAADAAADAAADAAADAAADAAADACbgAAAAIQGfc2pCvwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAm4AAAAClBm3dJqEFsmUwIV//+OEAAAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMC2wAAACNBn5VFFSwr/wAAAwAAAwAAAwAAAwAAAwAAAwAAAwAAAwAm4AAAACEBn7ZqQr8AAAMAAAMAAAMAAAMAAAMAAAMAAAMAAAMAJuE=';

    public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult
    {
        $wallStart = microtime(true);
        $targetPath = $options['target_path'] ?? $this->defaultPath($prompt, 'mp4');
        $sceneId = $options['scene_id'] ?? null;
        $startedAt = new \DateTimeImmutable('now');
        $timestamp = $startedAt->format(\DateTimeInterface::ATOM);

        $dir = \dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $content = $this->minimalMp4();
        file_put_contents($targetPath, $content);

        $completedAt = new \DateTimeImmutable('now');

        $modelLabel = 'fake-video';
        if (isset($options['replicate_model']) && is_string($options['replicate_model']) && $options['replicate_model'] !== '') {
            $modelLabel = $options['replicate_model'];
        } elseif (isset($options['replicate_preset']) && is_string($options['replicate_preset']) && $options['replicate_preset'] !== '') {
            try {
                $modelLabel = ReplicateVideoModelPresets::resolve($options['replicate_preset'])['model'];
            } catch (\InvalidArgumentException) {
                $modelLabel = $options['replicate_preset'];
            }
        }

        $metadata = [
            'provider' => self::PROVIDER_NAME,
            'generated_at' => $timestamp,
            'started_at' => $startedAt->format(\DateTimeInterface::ATOM),
            'completed_at' => $completedAt->format(\DateTimeInterface::ATOM),
            'generation_time_seconds' => round(microtime(true) - $wallStart, 3),
            'scene_id' => $sceneId,
            'prompt' => $prompt,
            'model' => $modelLabel,
        ];

        if (isset($options['replicate_preset']) && is_string($options['replicate_preset']) && $options['replicate_preset'] !== '') {
            $metadata['replicate_preset'] = $options['replicate_preset'];
        }

        $this->mergeRealAttemptHints($metadata, $options);

        return new GeneratedAssetResult(
            path: $targetPath,
            duration: 0.0,
            metadata: $metadata,
        );
    }

    private function defaultPath(string $prompt, string $ext): string
    {
        $hash = substr(hash('xxh128', $prompt), 0, 16);
        return sys_get_temp_dir() . '/fake_video_' . $hash . '.' . $ext;
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
                'real_attempt_replicate_preset',
            ] as $key
        ) {
            $v = $options[$key] ?? null;
            if (is_string($v) && $v !== '') {
                $metadata[$key] = $v;
            }
        }
    }

    /** @return string Minimal decodable MP4 black clip for pipeline and FFmpeg concat */
    private function minimalMp4(): string
    {
        $decoded = base64_decode(self::FALLBACK_MP4_BASE64, true);
        if ($decoded === false) {
            throw new \RuntimeException('Failed to decode bundled fake MP4 asset.');
        }

        return $decoded;
    }
}

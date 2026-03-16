<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider;

use App\Application\Trailer\DTO\GeneratedAssetResult;
use App\Application\Trailer\Port\VideoGenerationProviderInterface;

final class FakeVideoGenerationProvider implements VideoGenerationProviderInterface
{
    private const PROVIDER_NAME = 'fake-video';

    public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult
    {
        $targetPath = $options['target_path'] ?? $this->defaultPath($prompt, 'mp4');
        $sceneId = $options['scene_id'] ?? null;
        $timestamp = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);

        $dir = \dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $content = $this->minimalMp4();
        file_put_contents($targetPath, $content);

        $metadata = [
            'provider' => self::PROVIDER_NAME,
            'generated_at' => $timestamp,
            'scene_id' => $sceneId,
            'prompt' => $prompt,
        ];

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

    /** @return string Minimal deterministic MP4 container (ftyp + moov) for pipeline */
    private function minimalMp4(): string
    {
        $ftyp = "ftypmp42\0\0\0\0mp42isom";
        $moov = "\0\0\0\x20moov\0\0\0\x18mvhd\0\0\0\0\0\0\0\0\0\0\0\2\0\0\0\0\0\0\0\0";
        return $ftyp . $moov . str_repeat("\0", 512);
    }
}

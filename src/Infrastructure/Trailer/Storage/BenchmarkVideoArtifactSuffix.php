<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Storage;

use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoModelPresets;

/**
 * Derives the filename middle segment for benchmark scene videos: video--{suffix}.mp4
 * (e.g. hailuo-02-fast, seedance-1-lite, p-video-draft).
 *
 * Only returns a suffix when the caller passed explicit benchmark-related options
 * (preset, model override, or video_artifact_key). Provider-internal defaults alone
 * do not trigger this — non-benchmark flows keep video.mp4.
 */
final class BenchmarkVideoArtifactSuffix
{
    /**
     * @param array<string, mixed> $videoProviderOptions
     */
    public static function resolve(array $videoProviderOptions): ?string
    {
        $explicit = $videoProviderOptions['video_artifact_key'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return self::sanitize($explicit);
        }

        $preset = $videoProviderOptions['replicate_preset'] ?? null;
        $modelOverride = $videoProviderOptions['replicate_model'] ?? null;
        if (!is_string($preset) || $preset === '') {
            $preset = null;
        }
        if (!is_string($modelOverride) || $modelOverride === '') {
            $modelOverride = null;
        }

        if ($preset === null && $modelOverride === null) {
            return null;
        }

        $presetInput = [];
        $model = $modelOverride;
        if ($preset !== null) {
            $resolved = ReplicateVideoModelPresets::resolve($preset);
            $presetInput = $resolved['input'];
            if ($model === null) {
                $model = $resolved['model'];
            }
        }

        if ($model === null || $model === '') {
            return null;
        }

        $callInput = $videoProviderOptions['replicate_input'] ?? [];
        if (!is_array($callInput)) {
            $callInput = [];
        }
        $mergedInput = array_merge($presetInput, $callInput);

        return self::fromModelAndInput($model, $mergedInput);
    }

    /**
     * @param array<string, mixed> $mergedInput
     */
    private static function fromModelAndInput(string $model, array $mergedInput): string
    {
        $base = strtolower(basename(str_replace('\\', '/', $model)));
        if ($base === '') {
            $base = 'video';
        }
        if (($mergedInput['draft'] ?? false) === true) {
            $base .= '-draft';
        }

        return self::sanitize($base);
    }

    private static function sanitize(string $segment): string
    {
        $s = strtolower($segment);
        $s = preg_replace('/[^a-z0-9-]+/', '-', $s) ?? $s;
        $s = trim($s, '-');

        return $s !== '' ? $s : 'video';
    }
}

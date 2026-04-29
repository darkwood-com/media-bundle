<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Provider\Replicate;

/**
 * Named Replicate video targets for Scene 1 benchmarking.
 *
 * Override or extend inputs per model via the video provider `replicate_input` option
 * (merged after preset defaults).
 */
final class ReplicateVideoModelPresets
{
    public const HAILUO = 'hailuo';
    public const SEEDANCE = 'seedance';
    public const P_VIDEO_DRAFT = 'p_video_draft';

    /** CLI alias for {@see self::P_VIDEO_DRAFT} (`prunaai/p-video`). */
    public const CLI_VIDEO_PVIDEO = 'pvideo';

    /** @var array<string, array{model: string, input: array<string, mixed>}> */
    private const PRESETS = [
        self::HAILUO => [
            'model' => 'minimax/hailuo-02-fast',
            'input' => [],
        ],
        self::SEEDANCE => [
            'model' => 'bytedance/seedance-1-lite',
            'input' => [],
        ],
        self::P_VIDEO_DRAFT => [
            'model' => 'prunaai/p-video',
            'input' => ['draft' => true],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function presetKeys(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * Default Scene 1 presets for CLI `--benchmark-video` (excludes p-video).
     * Add {@see self::P_VIDEO_DRAFT} with `--include-pvideo`.
     *
     * @return list<string>
     */
    public static function coreBenchmarkPresetKeys(): array
    {
        return [self::HAILUO, self::SEEDANCE];
    }

    /**
     * Allowed values for `--video-model`.
     *
     * @return list<string>
     */
    public static function cliVideoModelChoices(): array
    {
        return [self::HAILUO, self::SEEDANCE, self::CLI_VIDEO_PVIDEO];
    }

    /**
     * Map CLI `--video-model` value to an internal preset key.
     */
    public static function presetKeyFromCliVideoModel(string $cli): string
    {
        $normalized = strtolower(trim($cli));
        if ($normalized === self::CLI_VIDEO_PVIDEO) {
            return self::P_VIDEO_DRAFT;
        }

        if ($normalized === self::HAILUO || $normalized === self::SEEDANCE) {
            return $normalized;
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown --video-model "%s". Use one of: %s',
            $cli,
            implode(', ', self::cliVideoModelChoices())
        ));
    }

    /**
     * @return array{model: string, input: array<string, mixed>}
     */
    public static function resolve(string $presetKey): array
    {
        if (!isset(self::PRESETS[$presetKey])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown Replicate video preset "%s". Known: %s',
                $presetKey,
                implode(', ', self::presetKeys())
            ));
        }

        return self::PRESETS[$presetKey];
    }
}

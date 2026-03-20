<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Provider\Replicate;

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

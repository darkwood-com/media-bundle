<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Video\Provider\Replicate;

use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoInputMapper;
use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoModelPresets;
use PHPUnit\Framework\TestCase;

final class ReplicateVideoInputMapperTest extends TestCase
{
    public function test_hailuo_maps_prompt_duration_seed(): void
    {
        $m = new ReplicateVideoInputMapper();
        $preset = ReplicateVideoModelPresets::resolve(ReplicateVideoModelPresets::HAILUO);
        $out = $m->buildInput($preset['model'], $preset['input'], 'Hello', [
            'duration' => 6,
            'seed' => 42,
        ]);

        self::assertSame('Hello', $out['prompt']);
        self::assertSame(6, $out['duration']);
        self::assertSame(42, $out['seed']);
    }

    public function test_seedance_maps_like_hailuo(): void
    {
        $m = new ReplicateVideoInputMapper();
        $preset = ReplicateVideoModelPresets::resolve(ReplicateVideoModelPresets::SEEDANCE);
        $out = $m->buildInput($preset['model'], $preset['input'], 'Scene', ['duration' => 5]);

        self::assertSame('Scene', $out['prompt']);
        self::assertSame(5, $out['duration']);
    }

    public function test_p_video_coerces_draft_boolean(): void
    {
        $m = new ReplicateVideoInputMapper();
        $preset = ReplicateVideoModelPresets::resolve(ReplicateVideoModelPresets::P_VIDEO_DRAFT);
        $out = $m->buildInput($preset['model'], $preset['input'], 'Draft clip', []);

        self::assertTrue($out['draft']);
        self::assertSame('Draft clip', $out['prompt']);
    }

    public function test_replicate_input_merges_over_preset(): void
    {
        $m = new ReplicateVideoInputMapper();
        $preset = ReplicateVideoModelPresets::resolve(ReplicateVideoModelPresets::P_VIDEO_DRAFT);
        $out = $m->buildInput($preset['model'], $preset['input'], 'X', [
            'replicate_input' => ['draft' => false],
        ]);

        self::assertFalse($out['draft']);
    }
}

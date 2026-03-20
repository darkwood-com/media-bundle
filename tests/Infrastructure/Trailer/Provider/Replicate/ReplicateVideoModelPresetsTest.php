<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Trailer\Provider\Replicate;

use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoModelPresets;
use PHPUnit\Framework\TestCase;

final class ReplicateVideoModelPresetsTest extends TestCase
{
    public function test_resolve_hailuo(): void
    {
        $r = ReplicateVideoModelPresets::resolve(ReplicateVideoModelPresets::HAILUO);
        self::assertSame('minimax/hailuo-02-fast', $r['model']);
        self::assertSame([], $r['input']);
    }

    public function test_resolve_seedance(): void
    {
        $r = ReplicateVideoModelPresets::resolve(ReplicateVideoModelPresets::SEEDANCE);
        self::assertSame('bytedance/seedance-1-lite', $r['model']);
    }

    public function test_resolve_p_video_draft_sets_draft_flag(): void
    {
        $r = ReplicateVideoModelPresets::resolve(ReplicateVideoModelPresets::P_VIDEO_DRAFT);
        self::assertSame('prunaai/p-video', $r['model']);
        self::assertTrue($r['input']['draft'] ?? false);
    }

    public function test_resolve_unknown_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReplicateVideoModelPresets::resolve('no-such-preset');
    }

    public function test_core_benchmark_excludes_p_video(): void
    {
        self::assertSame(
            [ReplicateVideoModelPresets::HAILUO, ReplicateVideoModelPresets::SEEDANCE],
            ReplicateVideoModelPresets::coreBenchmarkPresetKeys()
        );
    }

    public function test_preset_key_from_cli_video_model(): void
    {
        self::assertSame(
            ReplicateVideoModelPresets::P_VIDEO_DRAFT,
            ReplicateVideoModelPresets::presetKeyFromCliVideoModel('pvideo')
        );
        self::assertSame(
            ReplicateVideoModelPresets::HAILUO,
            ReplicateVideoModelPresets::presetKeyFromCliVideoModel('HAILUO')
        );
    }

    public function test_preset_key_from_cli_unknown_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReplicateVideoModelPresets::presetKeyFromCliVideoModel('other');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Video\Storage;

use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoModelPresets;
use App\Infrastructure\Video\Storage\BenchmarkVideoArtifactSuffix;
use PHPUnit\Framework\TestCase;

final class BenchmarkVideoArtifactSuffixTest extends TestCase
{
    public function test_empty_options_returns_null(): void
    {
        self::assertNull(BenchmarkVideoArtifactSuffix::resolve([]));
    }

    public function test_explicit_video_artifact_key(): void
    {
        self::assertSame(
            'my-custom-key',
            BenchmarkVideoArtifactSuffix::resolve(['video_artifact_key' => 'My Custom Key'])
        );
    }

    public function test_hailuo_preset_matches_model_basename(): void
    {
        self::assertSame(
            'hailuo-02-fast',
            BenchmarkVideoArtifactSuffix::resolve([
                'replicate_preset' => ReplicateVideoModelPresets::HAILUO,
            ])
        );
    }

    public function test_seedance_preset(): void
    {
        self::assertSame(
            'seedance-1-lite',
            BenchmarkVideoArtifactSuffix::resolve([
                'replicate_preset' => ReplicateVideoModelPresets::SEEDANCE,
            ])
        );
    }

    public function test_p_video_draft_preset_appends_draft(): void
    {
        self::assertSame(
            'p-video-draft',
            BenchmarkVideoArtifactSuffix::resolve([
                'replicate_preset' => ReplicateVideoModelPresets::P_VIDEO_DRAFT,
            ])
        );
    }

    public function test_model_override_without_preset(): void
    {
        self::assertSame(
            'some-model',
            BenchmarkVideoArtifactSuffix::resolve([
                'replicate_model' => 'org/some-model',
            ])
        );
    }

    public function test_replicate_input_draft_suffix_without_preset_draft_defaults(): void
    {
        self::assertSame(
            'p-video-draft',
            BenchmarkVideoArtifactSuffix::resolve([
                'replicate_model' => 'prunaai/p-video',
                'replicate_input' => ['draft' => true],
            ])
        );
    }
}

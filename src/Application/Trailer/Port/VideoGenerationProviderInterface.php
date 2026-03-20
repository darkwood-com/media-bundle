<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

use App\Application\Trailer\DTO\GeneratedAssetResult;

interface VideoGenerationProviderInterface
{
    /**
     * Generate a video from a text prompt.
     * Returns the path to the generated file (local or storage key).
     *
     * Common options: target_path, scene_id, scene_number, duration, seed.
     * scene_number is used by SceneAwareVideoGenerationProvider to route scene 1 to Replicate when configured.
     *
     * Replicate (real provider) also accepts:
     *  - replicate_preset: benchmark key (hailuo, seedance, p_video_draft)
     *  - replicate_model: model slug or version id (overrides preset / default config)
     *  - replicate_input: array merged into the API input object after preset defaults
     *  - video_artifact_key: optional slug for local filename (video--{slug}.mp4); takes precedence over preset/model naming
     *
     * @param array<string, mixed> $options Optional provider-specific options (e.g. resolution, duration)
     */
    public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult;
}

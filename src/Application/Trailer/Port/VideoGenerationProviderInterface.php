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
     * @param array<string, mixed> $options Optional provider-specific options (e.g. resolution, duration)
     */
    public function generateVideo(string $prompt, array $options = []): GeneratedAssetResult;
}

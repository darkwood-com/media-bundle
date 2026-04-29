<?php

declare(strict_types=1);

namespace App\Application\Video\Port;

use App\Application\Video\DTO\GeneratedAssetResult;

interface VoiceGenerationProviderInterface
{
    /**
     * Generate voice/narration audio from text.
     * Returns the path to the generated file (local or storage key).
     *
     * @param array<string, mixed> $options Optional provider-specific options (e.g. voice id)
     */
    public function generateVoice(string $text, array $options = []): GeneratedAssetResult;
}

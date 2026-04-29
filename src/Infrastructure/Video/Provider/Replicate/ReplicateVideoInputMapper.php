<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Provider\Replicate;

/**
 * Maps video-level options into Replicate `input` objects per model family.
 * Preset defaults and `replicate_input` are merged first; this class only
 * applies model-specific shape rules (no generic SDK).
 */
final class ReplicateVideoInputMapper
{
    /**
     * @param array<string, mixed> $presetInput   From {@see ReplicateVideoModelPresets}
     * @param array<string, mixed> $callOptions Provider options (duration, seed, replicate_input, …)
     *
     * @return array<string, mixed>
     */
    public function buildInput(string $model, array $presetInput, string $prompt, array $callOptions): array
    {
        $input = $presetInput;

        if (isset($callOptions['replicate_input']) && is_array($callOptions['replicate_input'])) {
            $input = array_merge($input, $callOptions['replicate_input']);
        }

        if ($this->isHailuo($model)) {
            return $this->applyHailuo($input, $prompt, $callOptions);
        }

        if ($this->isSeedance($model)) {
            return $this->applySeedance($input, $prompt, $callOptions);
        }

        if ($this->isPVideo($model)) {
            return $this->applyPVideo($input, $prompt, $callOptions);
        }

        return $this->applyGeneric($input, $prompt, $callOptions);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $callOptions
     *
     * @return array<string, mixed>
     */
    private function applyHailuo(array $input, string $prompt, array $callOptions): array
    {
        $input['prompt'] = $prompt;
        $this->applyOptionalDuration($input, $callOptions);
        $this->applyOptionalSeed($input, $callOptions);

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $callOptions
     *
     * @return array<string, mixed>
     */
    private function applySeedance(array $input, string $prompt, array $callOptions): array
    {
        $input['prompt'] = $prompt;
        $this->applyOptionalDuration($input, $callOptions);
        $this->applyOptionalSeed($input, $callOptions);

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $callOptions
     *
     * @return array<string, mixed>
     */
    private function applyPVideo(array $input, string $prompt, array $callOptions): array
    {
        $input['prompt'] = $prompt;
        if (array_key_exists('draft', $input)) {
            $input['draft'] = (bool) $input['draft'];
        }
        $this->applyOptionalDuration($input, $callOptions);
        $this->applyOptionalSeed($input, $callOptions);

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $callOptions
     *
     * @return array<string, mixed>
     */
    private function applyGeneric(array $input, string $prompt, array $callOptions): array
    {
        $input['prompt'] = $prompt;
        $this->applyOptionalDuration($input, $callOptions);
        $this->applyOptionalSeed($input, $callOptions);

        return $input;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $callOptions
     */
    private function applyOptionalDuration(array &$input, array $callOptions): void
    {
        if (isset($callOptions['duration'])) {
            $input['duration'] = (int) $callOptions['duration'];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $callOptions
     */
    private function applyOptionalSeed(array &$input, array $callOptions): void
    {
        if (isset($callOptions['seed'])) {
            $input['seed'] = $callOptions['seed'];
        }
    }

    private function isHailuo(string $model): bool
    {
        return str_starts_with($model, 'minimax/hailuo');
    }

    private function isSeedance(string $model): bool
    {
        return str_starts_with($model, 'bytedance/seedance');
    }

    private function isPVideo(string $model): bool
    {
        return str_starts_with($model, 'prunaai/p-video');
    }
}

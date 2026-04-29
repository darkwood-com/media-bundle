<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Rendering;

use App\Application\Video\Port\VideoProjectSetupInterface;
use App\Domain\Video\Asset;
use App\Domain\Video\Enum\AssetStatus;
use App\Domain\Video\Enum\AssetType;
use App\Domain\Video\VideoProject;
use App\Infrastructure\Video\Provider\Replicate\ReplicateVideoModelPresets;

/**
 * When scene 1 has multiple video assets (benchmark / multi-preset), writes
 * {@see self::JSON_FILENAME} and {@see self::MD_FILENAME} under the project render directory.
 */
final class VideoBenchmarkReportWriter
{
    public const JSON_FILENAME = 'video-benchmark-report.json';

    public const MD_FILENAME = 'video-benchmark-report.md';

    public function __construct(
        private readonly VideoProjectSetupInterface $projectSetup,
    ) {
    }

    /**
     * @return array{json: string, markdown: string}|null
     */
    public function writeIfApplicable(VideoProject $project): ?array
    {
        $firstScene = $project->scenes()[0] ?? null;
        if ($firstScene === null) {
            return null;
        }

        $videoAssets = [];
        foreach ($firstScene->assets() as $asset) {
            if ($asset->type() === AssetType::Video) {
                $videoAssets[] = $asset;
            }
        }

        if (\count($videoAssets) < 2) {
            return null;
        }

        usort($videoAssets, static function (Asset $a, Asset $b): int {
            return strcmp($a->id(), $b->id());
        });

        $renderDir = \dirname($this->projectSetup->getRenderOutputPath($project->id()));
        if (!is_dir($renderDir)) {
            mkdir($renderDir, 0755, true);
        }

        $sharedPrompt = $firstScene->videoPrompt();
        $rows = [];
        foreach ($videoAssets as $asset) {
            $rows[] = $this->rowFromAsset($asset, $sharedPrompt);
        }

        $payload = [
            'report_version' => 1,
            'generated_at' => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'project_id' => $project->id(),
            'scene_id' => $firstScene->id(),
            'scene_number' => $firstScene->number(),
            'shared_video_prompt' => $sharedPrompt,
            'models' => $rows,
        ];

        $jsonPath = $renderDir . '/' . self::JSON_FILENAME;
        $mdPath = $renderDir . '/' . self::MD_FILENAME;

        file_put_contents(
            $jsonPath,
            json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
        file_put_contents($mdPath, $this->toMarkdown($payload));

        return ['json' => $jsonPath, 'markdown' => $mdPath];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromAsset(Asset $asset, string $sharedPrompt): array
    {
        $meta = $asset->metadata();
        $preset = isset($meta['replicate_preset']) && is_string($meta['replicate_preset'])
            ? $meta['replicate_preset']
            : null;

        $modelName = $this->resolveModelDisplayName($meta, $preset);
        $localPath = isset($meta['local_path']) && is_string($meta['local_path'])
            ? $meta['local_path']
            : ($asset->path() ?? '');

        $promptUsed = isset($meta['prompt']) && is_string($meta['prompt']) && $meta['prompt'] !== ''
            ? $meta['prompt']
            : $sharedPrompt;

        $wallSeconds = $this->resolveGenerationTimeSeconds($meta);
        $predictSeconds = $this->resolveReplicatePredictTimeSeconds($meta);
        $costUsd = $this->resolveCostEstimateUsd($meta);

        return [
            'asset_id' => $asset->id(),
            'asset_status' => $asset->status()->value,
            'preset_key' => $preset,
            'model_name' => $modelName,
            'replicate_model_id' => isset($meta['model']) && is_string($meta['model']) ? $meta['model'] : null,
            'local_file_path' => $localPath,
            'prompt_used' => $promptUsed,
            'generation_time_seconds' => $wallSeconds,
            'replicate_predict_time_seconds' => $predictSeconds,
            'cost_estimate_usd' => $costUsd,
            'last_error' => $asset->status() === AssetStatus::Failed ? $asset->lastError() : null,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveModelDisplayName(array $meta, ?string $preset): string
    {
        if ($preset !== null && $preset !== '') {
            try {
                return ReplicateVideoModelPresets::resolve($preset)['model'];
            } catch (\InvalidArgumentException) {
            }
        }

        $m = $meta['model'] ?? null;

        return is_string($m) && $m !== '' ? $m : 'unknown';
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveGenerationTimeSeconds(array $meta): ?float
    {
        if (isset($meta['generation_time_seconds']) && is_numeric($meta['generation_time_seconds'])) {
            return round((float) $meta['generation_time_seconds'], 3);
        }

        $started = $meta['started_at'] ?? null;
        $completed = $meta['completed_at'] ?? null;
        if (!is_string($started) || $started === '' || !is_string($completed) || $completed === '') {
            return null;
        }

        try {
            $a = new \DateTimeImmutable($started);
            $b = new \DateTimeImmutable($completed);
            $seconds = $b->getTimestamp() - $a->getTimestamp();
            $seconds += ((int) $b->format('u') - (int) $a->format('u')) / 1_000_000;

            return round(max(0.0, $seconds), 3);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveReplicatePredictTimeSeconds(array $meta): ?float
    {
        $metrics = $meta['metrics'] ?? null;
        if (!is_array($metrics)) {
            return null;
        }

        $pt = $metrics['predict_time'] ?? null;
        if (is_numeric($pt)) {
            return round((float) $pt, 3);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveCostEstimateUsd(array $meta): ?float
    {
        $metrics = $meta['metrics'] ?? null;
        if (is_array($metrics)) {
            foreach (['cost', 'total_cost', 'cost_usd'] as $key) {
                if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                    return round((float) $metrics[$key], 4);
                }
            }
        }

        foreach (['cost', 'total_cost'] as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key])) {
                return round((float) $meta[$key], 4);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function toMarkdown(array $payload): string
    {
        $lines = [];
        $lines[] = '# Video benchmark report';
        $lines[] = '';
        $lines[] = sprintf('- **Project:** `%s`', $payload['project_id'] ?? '');
        $lines[] = sprintf('- **Scene:** #%d (`%s`)', (int) ($payload['scene_number'] ?? 0), $payload['scene_id'] ?? '');
        $lines[] = sprintf('- **Generated:** %s', $payload['generated_at'] ?? '');
        $lines[] = '';
        $lines[] = '## Shared prompt';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = (string) ($payload['shared_video_prompt'] ?? '');
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Per model';
        $lines[] = '';
        $lines[] = '| Preset | Model | Wall (s) | Replicate predict (s) | Cost est. (USD) | File | Status |';
        $lines[] = '|--------|-------|----------|-------------------------|-----------------|------|--------|';

        foreach ($payload['models'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $preset = $row['preset_key'] ?? '—';
            $model = str_replace('|', '\\|', (string) ($row['model_name'] ?? ''));
            $wall = $row['generation_time_seconds'] !== null ? (string) $row['generation_time_seconds'] : '—';
            $pred = $row['replicate_predict_time_seconds'] !== null ? (string) $row['replicate_predict_time_seconds'] : '—';
            $cost = $row['cost_estimate_usd'] !== null ? (string) $row['cost_estimate_usd'] : '—';
            $file = str_replace('|', '\\|', basename((string) ($row['local_file_path'] ?? '')));
            $status = (string) ($row['asset_status'] ?? '');
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | `%s` | %s |',
                is_string($preset) ? $preset : '—',
                $model,
                $wall,
                $pred,
                $cost,
                $file,
                $status,
            );
        }

        $lines[] = '';
        $lines[] = '## Local paths';
        $lines[] = '';

        foreach ($payload['models'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = sprintf(
                '- **%s** → `%s`',
                $row['model_name'] ?? '?',
                $row['local_file_path'] ?? '',
            );
        }

        return implode("\n", $lines) . "\n";
    }
}

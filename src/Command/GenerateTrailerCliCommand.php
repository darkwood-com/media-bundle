<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Trailer\Port\TrailerGenerationOrchestratorInterface;
use App\Domain\Trailer\Asset;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Enum\ProjectStatus;
use App\Domain\Trailer\Scene;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Command\Command;
use App\Infrastructure\Trailer\Provider\Replicate\ReplicateVideoModelPresets;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:trailer:generate',
    description: 'Generate a trailer from a YAML definition file.',
)]
final class GenerateTrailerCliCommand extends Command
{
    public function __construct(
        private readonly TrailerGenerationOrchestratorInterface $orchestrator,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'yaml',
            InputArgument::REQUIRED,
            'Path to the trailer definition YAML file',
        );

        $this->addOption(
            'video-model',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf(
                'Scene 1 only: single Replicate video preset (%s). Shorthand for one `--video-preset`',
                implode('|', ReplicateVideoModelPresets::cliVideoModelChoices())
            ),
        );

        $this->addOption(
            'benchmark-video',
            null,
            InputOption::VALUE_NONE,
            sprintf(
                'Scene 1 only: benchmark hailuo + seedance (voice skipped). Use --include-pvideo to add prunaai/p-video',
            ),
        );

        $this->addOption(
            'include-pvideo',
            null,
            InputOption::VALUE_NONE,
            'With --benchmark-video only: also run the prunaai/p-video (draft) preset',
        );

        $this->addOption(
            'video-preset',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf(
                'Replicate preset key(s) for scene 1 only (%s). Comma-separated = one project, multiple clips',
                implode(', ', ReplicateVideoModelPresets::presetKeys())
            ),
        );

        $this->addOption(
            'video-benchmark',
            null,
            InputOption::VALUE_NONE,
            sprintf(
                'Scene 1 only: all presets (%s) in one project; same as comma-separated --video-preset (legacy)',
                implode(', ', ReplicateVideoModelPresets::presetKeys())
            ),
        );

        $this->addOption(
            'replicate-model',
            null,
            InputOption::VALUE_REQUIRED,
            'Override Replicate model (slug or version id) for scene 1 video',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $yamlInput = $input->getArgument('yaml');

        $yamlPath = Path::isAbsolute($yamlInput)
            ? $yamlInput
            : Path::join(getcwd() ?: '', $yamlInput);

        if (!is_file($yamlPath)) {
            $io->error(sprintf('YAML file not found: %s', $yamlPath));
            return Command::FAILURE;
        }

        try {
            $firstSceneVideoOptions = $this->buildFirstSceneVideoOptions($input);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section('Generating trailer');
        $io->text('Loading definition and running pipeline…');

        try {
            $result = $this->orchestrator->generateFromYaml($yamlPath, $firstSceneVideoOptions);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $project = $result->project;
        $outputDir = $this->kernel->getProjectDir() . '/var/trailers/' . $project->id();

        $io->success('Done.');
        $io->table(
            ['Property', 'Value'],
            [
                ['Project ID', $project->id()],
                ['Status', $project->status()->value],
                ['Output directory', $outputDir],
            ],
        );

        TrailerRenderCliSummary::write($io, $result);

        $scenes = $project->scenes();
        $firstScene = $scenes[0] ?? null;
        if ($firstScene !== null) {
            $io->writeln('');
            $io->section('Scene 1 — provider routing');
            $this->renderScene1ProviderRoutingSummary(
                $io,
                $firstScene,
                $result->benchmarkReportPaths !== null,
            );
        }

        if ($firstScene !== null) {
            $videoAssets = [];
            foreach ($firstScene->assets() as $asset) {
                if ($asset->type() === AssetType::Video) {
                    $videoAssets[] = $asset;
                }
            }

            if ($videoAssets !== []) {
                $io->writeln('');
                $io->section('Scene 1 video' . (count($videoAssets) > 1 ? 's' : ''));

                $benchmarkPaths = $result->benchmarkReportPaths;
                if ($benchmarkPaths !== null) {
                    $io->writeln(sprintf('Benchmark report (JSON): %s', $benchmarkPaths['json']));
                    $io->writeln(sprintf('Benchmark report (Markdown): %s', $benchmarkPaths['markdown']));
                    $io->writeln('');
                    $jsonRaw = @file_get_contents($benchmarkPaths['json']);
                    if (is_string($jsonRaw) && $jsonRaw !== '') {
                        try {
                            /** @var array<string, mixed> $report */
                            $report = json_decode($jsonRaw, true, 512, \JSON_THROW_ON_ERROR);
                            $modelRows = $report['models'] ?? [];
                            if (is_array($modelRows) && $modelRows !== []) {
                                $table = [];
                                foreach ($modelRows as $row) {
                                    if (!is_array($row)) {
                                        continue;
                                    }
                                    $wall = $row['generation_time_seconds'];
                                    $pred = $row['replicate_predict_time_seconds'];
                                    $cost = $row['cost_estimate_usd'];
                                    $table[] = [
                                        (string) ($row['preset_key'] ?? '—'),
                                        (string) ($row['model_name'] ?? ''),
                                        $wall !== null ? (string) $wall : '—',
                                        $pred !== null ? (string) $pred : '—',
                                        $cost !== null ? (string) $cost : '—',
                                        basename((string) ($row['local_file_path'] ?? '')),
                                        (string) ($row['asset_status'] ?? ''),
                                    ];
                                }
                                $io->table(
                                    ['Preset', 'Model', 'Wall (s)', 'Predict (s)', 'Cost (USD)', 'File', 'Status'],
                                    $table,
                                );
                            }
                        } catch (\JsonException) {
                            $io->warning('Could not parse benchmark JSON for CLI summary.');
                        }
                    }
                    $io->writeln('');
                }

                if ($benchmarkPaths === null) {
                    foreach ($videoAssets as $i => $videoAsset) {
                        if ($i > 0) {
                            $io->writeln('');
                        }
                        $metadata = $videoAsset->metadata();
                        $provider = $metadata['provider'] ?? $videoAsset->provider() ?? 'unknown';
                        $fallbackFrom = $metadata['fallback_from'] ?? null;

                        $io->writeln(sprintf('Asset %s', $videoAsset->id()));
                        $file = $metadata['video_artifact_file'] ?? ($videoAsset->path() !== null ? basename($videoAsset->path()) : null);
                        if (is_string($file) && $file !== '') {
                            $io->writeln(sprintf('File: %s', $file));
                        }
                        $io->writeln(sprintf('Provider: %s', $provider));

                        $model = $metadata['model'] ?? null;
                        if (is_string($model) && $model !== '') {
                            $io->writeln(sprintf('Replicate model: %s', $model));
                        }

                        $preset = $metadata['replicate_preset'] ?? null;
                        if (is_string($preset) && $preset !== '') {
                            $io->writeln(sprintf('Video preset: %s', $preset));
                        }

                        $jobId = $metadata['prediction_id'] ?? $metadata['remote_job_id'] ?? null;
                        if (is_string($jobId) && $jobId !== '') {
                            $io->writeln(sprintf('Replicate prediction / job id: %s', $jobId));
                        }

                        $remoteUrl = $metadata['remote_output_url'] ?? null;
                        if (is_string($remoteUrl) && $remoteUrl !== '') {
                            $io->writeln(sprintf('Remote output URL: %s', $remoteUrl));
                        }

                        if ($fallbackFrom !== null) {
                            $io->writeln(sprintf('Used fallback from: %s', $fallbackFrom));
                        }
                    }
                }

                $io->writeln('');
                $io->text(sprintf(
                    '<comment>Extra metadata</comment> (prediction ids, URLs, timings) — %s/project.json',
                    $outputDir
                ));
            }
        }

        return $project->status() === ProjectStatus::Completed
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws \InvalidArgumentException on conflicting scene-1 video flags or unknown presets
     */
    private function buildFirstSceneVideoOptions(InputInterface $input): ?array
    {
        $presetRaw = $input->getOption('video-preset');
        $model = $input->getOption('replicate-model');
        $legacyBenchmark = (bool) $input->getOption('video-benchmark');
        $benchmarkVideo = (bool) $input->getOption('benchmark-video');
        $includePvideo = (bool) $input->getOption('include-pvideo');

        $videoModelRaw = $input->getOption('video-model');
        $videoModelCli = is_string($videoModelRaw) && $videoModelRaw !== ''
            ? strtolower(trim($videoModelRaw))
            : null;

        if (!is_string($model) || $model === '') {
            $model = null;
        }

        $fromPresetOption = [];
        if (is_string($presetRaw) && $presetRaw !== '') {
            $fromPresetOption = array_values(array_filter(
                array_map(trim(...), explode(',', $presetRaw)),
                static fn (string $s): bool => $s !== '',
            ));
        }

        foreach ($fromPresetOption as $key) {
            ReplicateVideoModelPresets::resolve($key);
        }

        $benchmarkPresets = null;
        if ($legacyBenchmark) {
            $benchmarkPresets = ReplicateVideoModelPresets::presetKeys();
        } elseif ($benchmarkVideo) {
            $benchmarkPresets = ReplicateVideoModelPresets::coreBenchmarkPresetKeys();
            if ($includePvideo) {
                $benchmarkPresets[] = ReplicateVideoModelPresets::P_VIDEO_DRAFT;
            }
        }

        if ($videoModelCli !== null && ($benchmarkPresets !== null || $fromPresetOption !== [])) {
            throw new \InvalidArgumentException(
                'Use only one of --video-model, --video-preset / --video-benchmark, or --benchmark-video.'
            );
        }

        if ($benchmarkPresets !== null && $fromPresetOption !== []) {
            throw new \InvalidArgumentException(
                'Do not combine --video-preset with --video-benchmark or --benchmark-video.'
            );
        }

        $presetList = [];
        if ($benchmarkPresets !== null) {
            $presetList = $benchmarkPresets;
        } elseif ($fromPresetOption !== []) {
            $presetList = $fromPresetOption;
        } elseif ($videoModelCli !== null) {
            $presetList = [ReplicateVideoModelPresets::presetKeyFromCliVideoModel($videoModelCli)];
        }

        if ($presetList === [] && $model === null) {
            return null;
        }

        $opts = [];
        if ($model !== null) {
            $opts['replicate_model'] = $model;
        }

        if (count($presetList) > 1) {
            $opts['replicate_benchmark_presets'] = $presetList;

            return $opts;
        }

        if (count($presetList) === 1) {
            $opts['replicate_preset'] = $presetList[0];
        }

        return $opts;
    }

    /**
     * Makes Scene 1 fake-vs-real routing obvious without opening project.json.
     */
    private function renderScene1ProviderRoutingSummary(SymfonyStyle $io, Scene $firstScene, bool $isVideoBenchmark): void
    {
        $voice = null;
        /** @var list<Asset> $videos */
        $videos = [];
        foreach ($firstScene->assets() as $asset) {
            if ($asset->type() === AssetType::Voice) {
                $voice = $asset;
            } elseif ($asset->type() === AssetType::Video) {
                $videos[] = $asset;
            }
        }

        $io->writeln('<fg=cyan>Voice</>');
        if ($voice === null) {
            $io->writeln('  <comment>No voice asset.</comment>');
        } else {
            foreach ($this->routingDetailLines($voice) as $line) {
                $io->writeln($line);
            }
        }

        $io->writeln('');
        $io->writeln('<fg=cyan>Video</>');
        if ($videos === []) {
            $io->writeln('  <comment>No video asset.</comment>');
        } elseif ($isVideoBenchmark && count($videos) > 1) {
            $io->writeln(sprintf(
                '  <info>Benchmark:</info> %d clip(s) run via <info>direct Replicate</info> (not the scene-router fake fallback).',
                count($videos)
            ));
            $failed = array_values(array_filter($videos, static fn (Asset $a): bool => $a->status() === AssetStatus::Failed));
            if ($failed !== []) {
                $io->writeln(sprintf('  <fg=red>%d benchmark clip(s) failed.</>', count($failed)));
                foreach ($failed as $asset) {
                    $err = $asset->lastError();
                    if (is_string($err) && $err !== '') {
                        $io->writeln('    <fg=yellow>' . OutputFormatter::escape($this->truncateForCli($err, 360)) . '</>');
                    }
                }
            } else {
                foreach ($videos as $i => $asset) {
                    $md = $asset->metadata();
                    $preset = $md['replicate_preset'] ?? null;
                    $p = (string) ($md['provider'] ?? $asset->provider() ?? 'unknown');
                    $label = is_string($preset) && $preset !== '' ? $preset : sprintf('#%d', $i + 1);
                    $io->writeln(sprintf(
                        '  <comment>%s</> → final <info>%s</>',
                        OutputFormatter::escape($label),
                        OutputFormatter::escape($p)
                    ));
                }
            }
        } else {
            foreach ($videos as $i => $asset) {
                if ($i > 0) {
                    $io->writeln('');
                }
                if (count($videos) > 1) {
                    $io->writeln(sprintf('  <comment>%s</>', OutputFormatter::escape($asset->id())));
                }
                foreach ($this->routingDetailLines($asset) as $line) {
                    $io->writeln($line);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function routingDetailLines(Asset $asset): array
    {
        $md = $asset->metadata();

        if (($md['skipped'] ?? false) === true) {
            $reason = isset($md['reason']) && is_string($md['reason']) && $md['reason'] !== ''
                ? $md['reason']
                : 'skipped';

            return [
                sprintf('  <comment>Skipped</> — %s', OutputFormatter::escape($reason)),
            ];
        }

        if ($asset->status() === AssetStatus::Failed) {
            $msg = $asset->lastError();
            $lines = ['  <fg=red>Failed</> — generation did not complete.'];
            if (is_string($msg) && $msg !== '') {
                $lines[] = '  Error: <fg=yellow>' . OutputFormatter::escape($this->truncateForCli($msg, 400)) . '</>';
            }
            $detail = $md['provider_error_message'] ?? null;
            if (is_string($detail) && $detail !== '' && $detail !== $msg) {
                $lines[] = '  Provider: <fg=yellow>' . OutputFormatter::escape($this->truncateForCli($detail, 400)) . '</>';
            }

            return $lines;
        }

        $provider = (string) ($md['provider'] ?? $asset->provider() ?? 'unknown');
        $fallback = ($md['fallback_from'] ?? null) === 'real';
        $realErr = $md['real_attempt_error_message'] ?? null;

        $isReplicate = str_starts_with($provider, 'replicate-');
        $isFake = str_starts_with($provider, 'fake-');

        $lines = [];

        if ($isReplicate && !$fallback) {
            $lines[] = '  Real provider attempted: <info>yes</> (succeeded)';
            $lines[] = '  Fake fallback used: <info>no</>';
        } elseif ($isFake && $fallback) {
            $lines[] = '  Real provider attempted: <info>yes</> (failed)';
            $lines[] = '  Fake fallback used: <comment>yes</>';
        } elseif ($isFake && !$fallback) {
            $lines[] = '  Real provider attempted: <comment>no</>';
            $lines[] = '  Fake fallback used: <info>no</>';
            $lines[] = '  <comment>Tip:</> enable Replicate (video/voice), set <options=bold>TRAILER_REAL_FOR_FIRST_SCENE_ONLY=1</> for all scenes real (or <options=bold>=0</> for scene 1 only), and configure <options=bold>TRAILER_VIDEO_REPLICATE_API_TOKEN</>.';
        } else {
            $lines[] = '  Real provider attempted: <comment>unknown</>';
            $lines[] = '  Fake fallback used: ' . ($fallback ? '<comment>yes</>' : '<info>no</>');
        }

        $lines[] = sprintf(
            '  Final provider (stored asset): <options=bold>%s</>',
            OutputFormatter::escape($provider)
        );

        if ($isFake && $fallback && is_string($realErr) && $realErr !== '') {
            $lines[] = '  Real attempt error: <fg=yellow>' . OutputFormatter::escape($this->truncateForCli($realErr, 420)) . '</>';
        }

        return $lines;
    }

    private function truncateForCli(string $message, int $maxLength): string
    {
        if ($maxLength < 4 || strlen($message) <= $maxLength) {
            return $message;
        }

        return substr($message, 0, $maxLength - 3) . '…';
    }
}

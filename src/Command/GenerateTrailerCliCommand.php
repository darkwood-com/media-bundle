<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Trailer\Service\TrailerGenerationOrchestrator;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Enum\ProjectStatus;
use Symfony\Component\Console\Attribute\AsCommand;
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
        private readonly TrailerGenerationOrchestrator $orchestrator,
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
            'video-preset',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf(
                'Replicate benchmark preset(s) for scene 1 only (%s). Comma-separated runs all in one project (e.g. hailuo,seedance,p_video_draft)',
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

        $io->section('Generating trailer');
        $io->text('Loading definition and running pipeline…');

        $firstSceneVideoOptions = $this->buildFirstSceneVideoOptions($input);

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

        $scenes = $project->scenes();
        $firstScene = $scenes[0] ?? null;
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

                    if ($fallbackFrom !== null) {
                        $io->writeln(sprintf('Used fallback from: %s', $fallbackFrom));
                    }
                }

                $io->writeln('');
                $io->writeln(sprintf(
                    'Detailed provider metadata is stored in %s/project.json',
                    $outputDir
                ));
            }
        }

        if ($result->renderOutputPath !== null) {
            $io->text(sprintf('Render output: %s', $result->renderOutputPath));
        }

        return $project->status() === ProjectStatus::Completed
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFirstSceneVideoOptions(InputInterface $input): ?array
    {
        $presetRaw = $input->getOption('video-preset');
        $model = $input->getOption('replicate-model');

        if (!is_string($model) || $model === '') {
            $model = null;
        }

        $presetList = [];
        if (is_string($presetRaw) && $presetRaw !== '') {
            $presetList = array_values(array_filter(
                array_map(trim(...), explode(',', $presetRaw)),
                static fn (string $s): bool => $s !== '',
            ));
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
}

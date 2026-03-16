<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Trailer\Service\TrailerGenerationOrchestrator;
use App\Domain\Trailer\Enum\ProjectStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
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

        try {
            $result = $this->orchestrator->generateFromYaml($yamlPath);
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

        if ($result->renderOutputPath !== null) {
            $io->text(sprintf('Render output: %s', $result->renderOutputPath));
        }

        return $project->status() === ProjectStatus::Completed
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}

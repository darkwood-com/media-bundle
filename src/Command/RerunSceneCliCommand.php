<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Video\Exception\ProjectNotFoundException;
use App\Application\Video\Exception\SceneNotFoundException;
use App\Application\Video\Service\SceneRerunService;
use App\Domain\Video\Enum\ProjectStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:video:rerun-scene',
    description: 'Rerun a single scene from an existing saved video project.',
)]
final class RerunSceneCliCommand extends Command
{
    public function __construct(
        private readonly SceneRerunService $sceneRerunService,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'project-id',
            InputArgument::REQUIRED,
            'ID of the saved video project',
        );
        $this->addArgument(
            'scene-id',
            InputArgument::REQUIRED,
            'ID of the scene to regenerate',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectId = $input->getArgument('project-id');
        $sceneId = $input->getArgument('scene-id');

        try {
            $result = $this->sceneRerunService->rerunScene($projectId, $sceneId);
        } catch (ProjectNotFoundException | SceneNotFoundException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $project = $result->project;
        $outputDir = $this->kernel->getProjectDir() . '/var/videos/' . $project->id();

        $io->success('Scene rerun done.');
        $io->table(
            ['Property', 'Value'],
            [
                ['Project ID', $project->id()],
                ['Scene ID', $sceneId],
                ['Status', $project->status()->value],
                ['Output directory', $outputDir],
            ],
        );

        VideoRenderCliSummary::write($io, $result);

        return $project->status() === ProjectStatus::Completed
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}

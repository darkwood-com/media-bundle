<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\SceneDefinition;
use App\Application\Trailer\DTO\TrailerDefinition;
use App\Application\Trailer\DTO\TrailerGenerationResult;
use App\Application\Trailer\Port\TrailerDefinitionLoaderInterface;
use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Application\Trailer\Port\TrailerRendererInterface;
use App\Domain\Trailer\Enum\SceneStatus;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;

/**
 * Orchestrates trailer generation from a YAML definition: load, create project,
 * persist, iterate scenes (generate assets), persist after each scene, then
 * render when all scenes completed. Marks project status as draft → processing
 * → completed or failed; persists incrementally so the run is inspectable.
 */
final class TrailerGenerationOrchestrator
{
    public function __construct(
        private readonly TrailerDefinitionLoaderInterface $definitionLoader,
        private readonly TrailerProjectRepositoryInterface $projectRepository,
        private readonly TrailerProjectSetupInterface $projectSetup,
        private readonly SceneGenerationService $sceneGenerationService,
        private readonly TrailerRendererInterface $renderer,
    ) {
    }

    /**
     * Generate a trailer project from a YAML definition file.
     * Persists initial state, after each scene, and final status; renders
     * the final output when all scenes completed successfully.
     *
     * @throws \App\Application\Trailer\Exception\InvalidTrailerDefinitionException
     */
    public function generateFromYaml(string $yamlPath): TrailerGenerationResult
    {
        $definition = $this->definitionLoader->load($yamlPath);
        $projectId = $this->createProjectId($yamlPath);

        $project = $this->createProject($projectId, $yamlPath, $definition);
        $this->projectSetup->prepareProjectDirectories($projectId);
        $this->projectSetup->copyInputYaml($projectId, $yamlPath);
        $this->projectRepository->save($project);

        $project->startProcessing();
        $this->projectRepository->save($project);

        $sceneDefinitions = $definition->scenes;
        $anyFailed = false;

        foreach ($project->scenes() as $index => $scene) {
            $sceneDef = $sceneDefinitions[$index] ?? null;
            if ($sceneDef instanceof SceneDefinition) {
                $this->sceneGenerationService->generateScene($projectId, $scene, $sceneDef);
            }
            $this->projectRepository->save($project);

            if ($scene->status() === SceneStatus::Failed) {
                $anyFailed = true;
            }
        }

        if ($anyFailed) {
            $project->fail();
        } else {
            $project->complete();
        }
        $this->projectRepository->save($project);

        $renderOutputPath = null;
        if ($project->status()->value === 'completed') {
            $renderOutputPath = $this->projectSetup->getRenderOutputPath($projectId);
            $this->renderer->render($project, $renderOutputPath);
            $this->projectRepository->save($project);
        }

        return new TrailerGenerationResult($project, $renderOutputPath);
    }

    private function createProjectId(string $yamlPath): string
    {
        $base = pathinfo($yamlPath, \PATHINFO_FILENAME);
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($base));
        $slug = trim($slug, '-') ?: 'trailer';

        return $slug . '-' . bin2hex(random_bytes(4));
    }

    private function createProject(string $projectId, string $sourcePath, TrailerDefinition $definition): TrailerProject
    {
        $project = new TrailerProject(
            id: $projectId,
            sourceScenarioPath: $sourcePath,
            title: $definition->title,
        );

        foreach ($definition->scenes as $number => $sceneDef) {
            $scene = new Scene(
                id: $sceneDef->id,
                number: $number + 1,
                title: $sceneDef->title,
                description: $sceneDef->description,
                videoPrompt: $sceneDef->videoPrompt,
                narrationText: $sceneDef->narration,
                duration: $sceneDef->duration,
            );
            $project->addScene($scene);
        }

        return $project;
    }
}

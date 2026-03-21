<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Trailer\DTO\TrailerDefinition;
use App\Application\Trailer\Port\TrailerDefinitionLoaderInterface;
use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;
use App\Flow\Model\TrailerGenerationPayload;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Flow step: load definition, create project, persist, start processing.
 *
 * @extends Flow<TrailerGenerationPayload, TrailerGenerationPayload>
 */
final class PrepareTrailerProjectFlow extends Flow
{
    public function __construct(
        private readonly TrailerDefinitionLoaderInterface $definitionLoader,
        private readonly TrailerProjectRepositoryInterface $projectRepository,
        private readonly TrailerProjectSetupInterface $projectSetup,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof TrailerGenerationPayload) {
                return $payload;
            }

            return $this->prepare($payload);
        };

        parent::__construct(
            $job,
            null,
            new LinearIpStrategy(),
            null,
            new AsyncHandler(),
            $driver ?? new FiberDriver(),
        );
    }

    private function prepare(TrailerGenerationPayload $payload): TrailerGenerationPayload
    {
        $definition = $this->definitionLoader->load($payload->yamlPath);
        $payload->definition = $definition;

        $projectId = $this->createProjectId($payload->yamlPath);
        $payload->projectId = $projectId;

        $project = $this->createProject($projectId, $payload->yamlPath, $definition);
        $payload->project = $project;

        $this->projectSetup->prepareProjectDirectories($projectId);
        $this->projectSetup->copyInputYaml($projectId, $payload->yamlPath);
        $this->projectRepository->save($project);

        $project->startProcessing();
        $this->projectRepository->save($project);

        return $payload;
    }

    private function createProjectId(string $yamlPath): string
    {
        $base = pathinfo($yamlPath, \PATHINFO_FILENAME);
        $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower((string) $base));
        $slug = trim((string) $slug, '-') ?: 'trailer';

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

<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Flow\Model\TrailerGenerationPayload;
use App\Infrastructure\Trailer\Persistence\JsonTrailerProjectMapper;
use App\Infrastructure\Trailer\Rendering\SceneClipRenderReport;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;
use Spatie\Fork\Fork;

/**
 * Flow step: run one scene flow per scene; scenes may execute in parallel (Spatie Fork) when enabled.
 * Parent merges fork results and persists so project.json stays consistent.
 *
 * @extends Flow<TrailerGenerationPayload, TrailerGenerationPayload>
 */
final class ProcessTrailerScenesFlow extends Flow
{
    public function __construct(
        private readonly TrailerSceneStep $sceneStep,
        private readonly JsonTrailerProjectMapper $projectMapper,
        private readonly TrailerProjectRepositoryInterface $projectRepository,
        private readonly int $maxConcurrentScenes,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof TrailerGenerationPayload) {
                return $payload;
            }

            return $this->processScenes($payload);
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

    private function processScenes(TrailerGenerationPayload $payload): TrailerGenerationPayload
    {
        $project = $payload->project;
        if ($project === null) {
            return $payload;
        }

        $count = \count($project->scenes());
        if ($count === 0) {
            return $payload;
        }

        if ($count === 1 || !$this->isSceneForkParallelEnabled()) {
            foreach ($project->scenes() as $index => $_scene) {
                $this->sceneStep->processForGeneration($payload, $index);
            }

            return $payload;
        }

        $callables = [];
        for ($i = 0; $i < $count; $i++) {
            $callables[] = (function (int $sceneIndex) use ($payload) {
                return fn () => $this->sceneStep->processSceneForFork($payload, $sceneIndex);
            })($i);
        }

        $maxParallel = min(max(1, $this->maxConcurrentScenes), $count);
        $results = Fork::new()->concurrent($maxParallel)->run(...$callables);

        $ordered = array_values($results);
        usort($ordered, static fn (array $a, array $b): int => ($a['sceneIndex'] ?? 0) <=> ($b['sceneIndex'] ?? 0));

        foreach ($ordered as $result) {
            $this->mergeForkSceneResult($payload, $result);
        }

        return $payload;
    }

    /**
     * @param array{sceneIndex: int, sceneData: array<string, mixed>, clipReport: array<string, mixed>, anyFailed: bool} $result
     */
    private function mergeForkSceneResult(TrailerGenerationPayload $payload, array $result): void
    {
        $project = $payload->project;
        if ($project === null) {
            return;
        }

        $index = $result['sceneIndex'];
        $scene = $this->projectMapper->sceneFromArray($result['sceneData']);
        $this->projectMapper->replaceSceneAtIndex($project, $index, $scene);
        $payload->sceneClipReports[] = SceneClipRenderReport::fromArray($result['clipReport']);
        if ($result['anyFailed'] ?? false) {
            $payload->anyFailed = true;
        }

        $this->projectRepository->save($project);
    }

    private function isSceneForkParallelEnabled(): bool
    {
        if (!\function_exists('pcntl_fork')) {
            return false;
        }
        if (!class_exists(Fork::class)) {
            return false;
        }

        $v = $_ENV['TRAILER_PARALLEL_FORK'] ?? getenv('TRAILER_PARALLEL_FORK');
        if ($v === false || $v === '') {
            return true;
        }

        return $v !== '0';
    }
}

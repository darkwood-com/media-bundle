<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Video\Port\VideoProjectRepositoryInterface;
use App\Flow\Model\VideoGenerationPayload;
use App\Infrastructure\Video\Persistence\JsonVideoProjectMapper;
use App\Infrastructure\Video\Rendering\SceneClipRenderReport;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;
use Spatie\Fork\Fork;

/**
 * Flow step: run one scene flow per scene; scenes may execute in parallel (Spatie Fork) when enabled.
 * Fork workers persist each scene via {@see VideoProjectRepositoryInterface::mergeSceneAtIndex} as they finish;
 * the parent merges in-memory state and reloads the project from disk once all workers return.
 *
 * @extends Flow<VideoGenerationPayload, VideoGenerationPayload>
 */
final class ProcessVideoScenesFlow extends Flow
{
    public function __construct(
        private readonly VideoSceneStep $sceneStep,
        private readonly JsonVideoProjectMapper $projectMapper,
        private readonly VideoProjectRepositoryInterface $projectRepository,
        private readonly int $maxConcurrentScenes,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof VideoGenerationPayload) {
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

    private function processScenes(VideoGenerationPayload $payload): VideoGenerationPayload
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

        $ordered = self::sortForkSceneResultsBySceneIndex($results);

        foreach ($ordered as $result) {
            $this->mergeForkSceneResult($payload, $result);
        }

        $reloaded = $this->projectRepository->get($payload->projectId);
        if ($reloaded !== null) {
            $payload->project = $reloaded;
        }

        return $payload;
    }

    /**
     * Ensures fork worker results are merged in scene index order (completion order may differ).
     *
     * @param list<array{sceneIndex: int, sceneData: array<string, mixed>, clipReport: array<string, mixed>, anyFailed?: bool}> $results
     *
     * @return list<array{sceneIndex: int, sceneData: array<string, mixed>, clipReport: array<string, mixed>, anyFailed?: bool}>
     */
    public static function sortForkSceneResultsBySceneIndex(array $results): array
    {
        $ordered = array_values($results);
        usort($ordered, static fn (array $a, array $b): int => ($a['sceneIndex'] ?? 0) <=> ($b['sceneIndex'] ?? 0));

        return $ordered;
    }

    /**
     * @param array{sceneIndex: int, sceneData: array<string, mixed>, clipReport: array<string, mixed>, anyFailed: bool} $result
     */
    private function mergeForkSceneResult(VideoGenerationPayload $payload, array $result): void
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
    }

    private function isSceneForkParallelEnabled(): bool
    {
        if (!\function_exists('pcntl_fork')) {
            return false;
        }
        if (!class_exists(Fork::class)) {
            return false;
        }

        $v = $_ENV['VIDEO_PARALLEL_FORK'] ?? getenv('VIDEO_PARALLEL_FORK');
        if ($v === false || $v === '') {
            return true;
        }

        return $v !== '0';
    }
}

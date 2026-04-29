<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Video\DTO\SceneDefinition;
use App\Application\Video\Port\VideoProjectRepositoryInterface;
use App\Application\Video\Service\SceneGenerationService;
use App\Domain\Video\Enum\SceneStatus;
use App\Domain\Video\Scene;
use App\Flow\Model\VideoGenerationPayload;
use App\Flow\Model\VideoScenePayload;
use App\Infrastructure\Video\Persistence\JsonVideoProjectMapper;
use App\Infrastructure\Video\Rendering\SceneClipFfmpegRenderer;
use App\Infrastructure\Video\Rendering\SceneClipRenderReport;
use App\Infrastructure\Video\Rendering\VideoRenderingMetadata;
use App\Infrastructure\Video\Storage\LocalArtifactStorage;

/**
 * Single-scene pipeline: generate assets, render clip, persist scene clip metadata, save project.
 * Shared by {@see ProcessVideoScenesFlow} and {@see VideoSceneGenerationFlow}.
 *
 * Not final so PHPUnit can generate test doubles for flow orchestration tests.
 */
class VideoSceneStep
{
    public function __construct(
        private readonly SceneGenerationService $sceneGenerationService,
        private readonly SceneClipFfmpegRenderer $sceneClipRenderer,
        private readonly LocalArtifactStorage $artifactStorage,
        private readonly VideoProjectRepositoryInterface $projectRepository,
        private readonly JsonVideoProjectMapper $projectMapper,
    ) {
    }

    public function process(VideoScenePayload $payload): VideoScenePayload
    {
        $this->runSceneGeneration($payload);

        $generation = $payload->generation;
        $project = $generation->project;
        $index = $payload->sceneIndex;
        if ($project === null) {
            return $payload;
        }

        $scene = $project->scenes()[$index] ?? null;
        if (!$scene instanceof Scene) {
            return $payload;
        }

        $clipReport = $payload->clipReport;
        if ($clipReport instanceof SceneClipRenderReport) {
            $generation->sceneClipReports[] = $clipReport;
        }

        $this->projectRepository->save($project);

        if ($scene->status() === SceneStatus::Failed) {
            $generation->anyFailed = true;
        }

        return $payload;
    }

    /**
     * Runs generation + scene mux, persists this scene into project.json under a file lock (parallel-safe),
     * then returns data for the parent Flow (clip report ordering). Does not append parent clip reports.
     */
    public function processSceneForFork(VideoGenerationPayload $generation, int $sceneIndex): array
    {
        $payload = new VideoScenePayload($generation, $sceneIndex);
        $this->runSceneGeneration($payload);

        $project = $generation->project;
        if ($project === null) {
            throw new \LogicException('VideoGenerationPayload has no project during fork merge.');
        }

        $scene = $project->scenes()[$sceneIndex] ?? null;
        if (!$scene instanceof Scene) {
            throw new \LogicException('Scene not found at index ' . $sceneIndex . ' during fork merge.');
        }

        $this->projectRepository->mergeSceneAtIndex($generation->projectId, $sceneIndex, $scene);

        $clipReport = $payload->clipReport;
        if (!$clipReport instanceof SceneClipRenderReport) {
            $clipReport = new SceneClipRenderReport(
                $scene->id(),
                $scene->number(),
                SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
            );
        }

        return [
            'sceneIndex' => $sceneIndex,
            'sceneData' => $this->projectMapper->sceneToArray($scene),
            'clipReport' => $clipReport->toArray(),
            'anyFailed' => $scene->status() === SceneStatus::Failed,
        ];
    }

    /**
     * Runs one scene using a {@see VideoGenerationPayload}; updates the payload in place.
     */
    public function processForGeneration(VideoGenerationPayload $generation, int $sceneIndex): void
    {
        $this->process(new VideoScenePayload($generation, $sceneIndex));
    }

    private function runSceneGeneration(VideoScenePayload $payload): void
    {
        $generation = $payload->generation;
        $project = $generation->project;
        $definition = $generation->definition;
        if ($project === null || $definition === null) {
            return;
        }

        $projectId = $generation->projectId;
        $sceneDefinitions = $definition->scenes;
        $index = $payload->sceneIndex;
        $scenes = $project->scenes();
        $scene = $scenes[$index] ?? null;
        if (!$scene instanceof Scene) {
            return;
        }

        $sceneDef = $sceneDefinitions[$index] ?? null;
        $firstSceneVideoOptions = $generation->firstSceneVideoOptions;

        if ($sceneDef instanceof SceneDefinition) {
            $benchmarkPresets = [];
            if ($index === 0 && $firstSceneVideoOptions !== null) {
                $raw = $firstSceneVideoOptions['replicate_benchmark_presets'] ?? null;
                if (is_array($raw)) {
                    $benchmarkPresets = array_values(array_filter(
                        $raw,
                        static fn ($p): bool => is_string($p) && $p !== '',
                    ));
                }
            }

            if ($index === 0 && $benchmarkPresets !== [] && $firstSceneVideoOptions !== null) {
                $baseVideo = $firstSceneVideoOptions;
                unset($baseVideo['replicate_benchmark_presets']);
                $this->sceneGenerationService->generateSceneWithVideoBenchmarkPresets(
                    $projectId,
                    $scene,
                    $sceneDef,
                    $benchmarkPresets,
                    $baseVideo,
                );
            } else {
                $videoOpts = ($index === 0 && $firstSceneVideoOptions !== null) ? $firstSceneVideoOptions : [];
                if ($index === 0) {
                    unset($videoOpts['replicate_benchmark_presets']);
                }
                $this->sceneGenerationService->generateScene($projectId, $scene, $sceneDef, $videoOpts);
            }
        }

        // scene.mp4 only when the scene completed successfully; failed scenes skip mux (renderer is defensive too).
        if ($scene->status() === SceneStatus::Completed) {
            $clipReport = $this->sceneClipRenderer->renderIfPossible($projectId, $scene);
        } else {
            $clipReport = new SceneClipRenderReport(
                $scene->id(),
                $scene->number(),
                SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
            );
        }

        $payload->clipReport = $clipReport;

        $scene->setClipRender(VideoRenderingMetadata::sceneClipPersist(
            $clipReport,
            $this->artifactStorage,
            $projectId,
            $scene,
        ));
    }
}

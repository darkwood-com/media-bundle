<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\SceneDefinition;
use App\Application\Trailer\DTO\TrailerGenerationResult;
use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Application\Trailer\Exception\ProjectNotFoundException;
use App\Application\Trailer\Exception\SceneNotFoundException;
use App\Application\Trailer\Port\TrailerRendererInterface;
use App\Domain\Trailer\Enum\SceneStatus;
use App\Infrastructure\Trailer\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Trailer\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Trailer\Rendering\SceneClipFfmpegRenderer;
use App\Infrastructure\Trailer\Rendering\SceneClipRenderReport;
use App\Infrastructure\Trailer\Rendering\TrailerRenderingMetadata;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;

/**
 * Reruns a single scene of an existing saved project: load project, reset the
 * scene, regenerate its assets, update project status, persist, rerender the
 * manifest, and rebuild render/scenario.mp4 from valid scene clips.
 */
final class SceneRerunService
{
    public function __construct(
        private readonly TrailerProjectRepositoryInterface $projectRepository,
        private readonly TrailerProjectSetupInterface $projectSetup,
        private readonly SceneGenerationService $sceneGenerationService,
        private readonly TrailerRendererInterface $renderer,
        private readonly SceneClipFfmpegRenderer $sceneClipRenderer,
        private readonly ScenarioConcatFfmpegRenderer $scenarioConcatRenderer,
        private readonly RenderingSummaryJsonWriter $renderingSummaryWriter,
        private readonly LocalArtifactStorage $artifactStorage,
    ) {
    }

    /**
     * Load project from repository, regenerate the given scene, update state, and rerender manifest.
     *
     * @throws ProjectNotFoundException
     * @throws SceneNotFoundException
     */
    public function rerunScene(string $projectId, string $sceneId): TrailerGenerationResult
    {
        $project = $this->projectRepository->get($projectId);
        if ($project === null) {
            throw new ProjectNotFoundException($projectId);
        }

        $scene = $this->findScene($project, $sceneId);
        if ($scene === null) {
            throw new SceneNotFoundException($projectId, $sceneId);
        }

        $definition = new SceneDefinition(
            id: $scene->id(),
            title: $scene->title(),
            description: $scene->description(),
            videoPrompt: $scene->videoPrompt(),
            narration: $scene->narrationText(),
            duration: $scene->duration(),
        );

        $scene->resetForRerun();
        $this->projectSetup->prepareProjectDirectories($projectId);
        $this->sceneGenerationService->generateScene($projectId, $scene, $definition);

        $rerunClipReport = null;
        if ($scene->status() === SceneStatus::Completed) {
            $rerunClipReport = $this->sceneClipRenderer->renderIfPossible($projectId, $scene);
        }

        $this->updateProjectStatus($project);
        $this->projectRepository->save($project);

        $renderOutputPath = $this->renderer->render(
            $project,
            $this->projectSetup->getRenderOutputPath($projectId)
        );

        $scenarioConcat = $this->scenarioConcatRenderer->concatIfPossible($projectId, $project);

        /** @var list<SceneClipRenderReport> $sceneClipReports */
        $sceneClipReports = [];
        foreach ($project->scenes() as $s) {
            if ($s->id() === $sceneId) {
                $sceneClipReports[] = $rerunClipReport ?? new SceneClipRenderReport(
                    $s->id(),
                    $s->number(),
                    SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
                );
            } else {
                $sceneClipReports[] = $this->sceneClipRenderer->classifySceneClip($projectId, $s);
            }
        }

        $this->renderingSummaryWriter->write(
            $this->projectSetup->getRenderOutputDir($projectId),
            $sceneClipReports,
            $scenarioConcat,
        );

        foreach ($project->scenes() as $i => $s) {
            $s->setClipRender(TrailerRenderingMetadata::sceneClipPersist(
                $sceneClipReports[$i],
                $this->artifactStorage,
                $projectId,
                $s,
            ));
        }

        $project->setRendering(TrailerRenderingMetadata::projectRenderingFromScenario($scenarioConcat));
        $this->projectRepository->save($project);

        return new TrailerGenerationResult(
            $project,
            $renderOutputPath,
            null,
            $scenarioConcat->outputPath,
            $scenarioConcat->skipReason,
        );
    }

    private function findScene(TrailerProject $project, string $sceneId): ?Scene
    {
        foreach ($project->scenes() as $scene) {
            if ($scene->id() === $sceneId) {
                return $scene;
            }
        }
        return null;
    }

    private function updateProjectStatus(TrailerProject $project): void
    {
        foreach ($project->scenes() as $scene) {
            if ($scene->status() === SceneStatus::Failed) {
                $project->fail();
                return;
            }
        }
        $project->complete();
    }
}

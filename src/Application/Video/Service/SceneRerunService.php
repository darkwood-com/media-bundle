<?php

declare(strict_types=1);

namespace App\Application\Video\Service;

use App\Application\Video\DTO\SceneDefinition;
use App\Application\Video\DTO\VideoGenerationResult;
use App\Application\Video\Port\VideoProjectRepositoryInterface;
use App\Application\Video\Port\VideoProjectSetupInterface;
use App\Application\Video\Exception\ProjectNotFoundException;
use App\Application\Video\Exception\SceneNotFoundException;
use App\Application\Video\Port\VideoRendererInterface;
use App\Domain\Video\Enum\SceneStatus;
use App\Infrastructure\Video\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Video\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Video\Rendering\SceneClipFfmpegRenderer;
use App\Infrastructure\Video\Rendering\SceneClipRenderReport;
use App\Infrastructure\Video\Rendering\VideoRenderingMetadata;
use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;
use App\Infrastructure\Video\Storage\LocalArtifactStorage;

/**
 * Reruns a single scene of an existing saved project: load project, reset the
 * scene, regenerate its assets, update project status, persist, rerender the
 * manifest, and rebuild render/scenario.mp4 from valid scene clips.
 */
final class SceneRerunService
{
    public function __construct(
        private readonly VideoProjectRepositoryInterface $projectRepository,
        private readonly VideoProjectSetupInterface $projectSetup,
        private readonly SceneGenerationService $sceneGenerationService,
        private readonly VideoRendererInterface $renderer,
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
    public function rerunScene(string $projectId, string $sceneId): VideoGenerationResult
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
            $s->setClipRender(VideoRenderingMetadata::sceneClipPersist(
                $sceneClipReports[$i],
                $this->artifactStorage,
                $projectId,
                $s,
            ));
        }

        $project->setRendering(VideoRenderingMetadata::projectRenderingFromScenario($scenarioConcat));
        $this->projectRepository->save($project);

        return new VideoGenerationResult(
            $project,
            $renderOutputPath,
            null,
            $scenarioConcat->outputPath,
            $scenarioConcat->skipReason,
        );
    }

    private function findScene(VideoProject $project, string $sceneId): ?Scene
    {
        foreach ($project->scenes() as $scene) {
            if ($scene->id() === $sceneId) {
                return $scene;
            }
        }
        return null;
    }

    private function updateProjectStatus(VideoProject $project): void
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

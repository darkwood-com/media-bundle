<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\SceneDefinition;
use App\Application\Trailer\DTO\TrailerDefinition;
use App\Application\Trailer\DTO\TrailerGenerationResult;
use App\Application\Trailer\Port\TrailerDefinitionLoaderInterface;
use App\Application\Trailer\Port\TrailerGenerationOrchestratorInterface;
use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Application\Trailer\Port\TrailerRendererInterface;
use App\Domain\Trailer\Enum\SceneStatus;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;
use App\Infrastructure\Trailer\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Trailer\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Trailer\Rendering\SceneClipFfmpegRenderer;
use App\Infrastructure\Trailer\Rendering\SceneClipRenderReport;
use App\Infrastructure\Trailer\Rendering\TrailerRenderingMetadata;
use App\Infrastructure\Trailer\Rendering\VideoBenchmarkReportWriter;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;

/**
 * Orchestrates trailer generation from a YAML definition: load, create project,
 * persist, iterate scenes (generate assets), persist after each scene, then
 * render when all scenes completed. Marks project status as draft → processing
 * → completed or failed; persists incrementally so the run is inspectable.
 */
final class TrailerGenerationOrchestrator implements TrailerGenerationOrchestratorInterface
{
    public function __construct(
        private readonly TrailerDefinitionLoaderInterface $definitionLoader,
        private readonly TrailerProjectRepositoryInterface $projectRepository,
        private readonly TrailerProjectSetupInterface $projectSetup,
        private readonly SceneGenerationService $sceneGenerationService,
        private readonly TrailerRendererInterface $renderer,
        private readonly VideoBenchmarkReportWriter $benchmarkReportWriter,
        private readonly SceneClipFfmpegRenderer $sceneClipRenderer,
        private readonly ScenarioConcatFfmpegRenderer $scenarioConcatRenderer,
        private readonly RenderingSummaryJsonWriter $renderingSummaryWriter,
        private readonly LocalArtifactStorage $artifactStorage,
    ) {
    }

    /**
     * Generate a trailer project from a YAML definition file.
     * Persists initial state, after each scene, and final status; renders
     * the final output when all scenes completed successfully.
     *
     * @throws \App\Application\Trailer\Exception\InvalidTrailerDefinitionException
     */
    /**
     * @param array<string, mixed>|null $firstSceneVideoOptions Passed to the video provider for scene 1 only.
     *        Use replicate_preset / replicate_model for a single clip, or replicate_benchmark_presets (list of preset keys)
     *        for scene-1 video-only benchmark: same prompt, multiple outputs; voice is skipped for that scene.
     */
    public function generateFromYaml(string $yamlPath, ?array $firstSceneVideoOptions = null): TrailerGenerationResult
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
        /** @var list<SceneClipRenderReport> $sceneClipReports */
        $sceneClipReports = [];

        foreach ($project->scenes() as $index => $scene) {
            $sceneDef = $sceneDefinitions[$index] ?? null;
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

                if ($index === 0 && $benchmarkPresets !== []) {
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

            if ($scene->status() === SceneStatus::Completed) {
                $sceneClipReports[] = $this->sceneClipRenderer->renderIfPossible($projectId, $scene);
            } else {
                $sceneClipReports[] = new SceneClipRenderReport(
                    $scene->id(),
                    $scene->number(),
                    SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
                );
            }

            $lastClipReport = $sceneClipReports[\count($sceneClipReports) - 1];
            $scene->setClipRender(TrailerRenderingMetadata::sceneClipPersist(
                $lastClipReport,
                $this->artifactStorage,
                $projectId,
                $scene,
            ));

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

        $benchmarkReportPaths = $this->benchmarkReportWriter->writeIfApplicable($project);

        $scenarioConcat = $this->scenarioConcatRenderer->concatIfPossible($projectId, $project);

        $this->renderingSummaryWriter->write(
            $this->projectSetup->getRenderOutputDir($projectId),
            $sceneClipReports,
            $scenarioConcat,
        );

        $project->setRendering(TrailerRenderingMetadata::projectRenderingFromScenario($scenarioConcat));
        $this->projectRepository->save($project);

        $renderOutputPath = null;
        if ($project->status()->value === 'completed') {
            $renderOutputPath = $this->renderer->render(
                $project,
                $this->projectSetup->getRenderOutputPath($projectId)
            );
            $this->projectRepository->save($project);
        }

        return new TrailerGenerationResult(
            $project,
            $renderOutputPath,
            $benchmarkReportPaths,
            $scenarioConcat->outputPath,
            $scenarioConcat->skipReason,
        );
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

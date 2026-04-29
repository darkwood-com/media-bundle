<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Video\DTO\VideoGenerationResult;
use App\Application\Video\Port\VideoProjectRepositoryInterface;
use App\Application\Video\Port\VideoProjectSetupInterface;
use App\Application\Video\Port\VideoRendererInterface;
use App\Flow\Model\VideoGenerationPayload;
use App\Infrastructure\Video\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Video\Rendering\SceneClipRenderReport;
use App\Infrastructure\Video\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Video\Rendering\VideoRenderingMetadata;
use App\Infrastructure\Video\Rendering\VideoBenchmarkReportWriter;
use App\Infrastructure\Video\Storage\LocalArtifactStorage;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Flow step: finalize project status, benchmark reports, scenario concat, rendering summary, manifest render.
 * Scenario concat runs here only — after all scene flows in the pipeline have finished — and remains sequential.
 *
 * @extends Flow<VideoGenerationPayload, VideoGenerationPayload>
 */
final class FinalizeVideoProjectFlow extends Flow
{
    public function __construct(
        private readonly VideoProjectRepositoryInterface $projectRepository,
        private readonly VideoRendererInterface $renderer,
        private readonly VideoBenchmarkReportWriter $benchmarkReportWriter,
        private readonly ScenarioConcatFfmpegRenderer $scenarioConcatRenderer,
        private readonly RenderingSummaryJsonWriter $renderingSummaryWriter,
        private readonly LocalArtifactStorage $artifactStorage,
        private readonly VideoProjectSetupInterface $projectSetup,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof VideoGenerationPayload) {
                return $payload;
            }

            return $this->finalize($payload);
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

    private function finalize(VideoGenerationPayload $payload): VideoGenerationPayload
    {
        $project = $payload->project;
        if ($project === null) {
            return $payload;
        }

        $projectId = $payload->projectId;

        if ($payload->anyFailed) {
            $project->fail();
        } else {
            $project->complete();
        }
        $this->projectRepository->save($project);

        $benchmarkReportPaths = $this->benchmarkReportWriter->writeIfApplicable($project);
        $payload->benchmarkReportPaths = $benchmarkReportPaths;

        $scenarioConcat = $this->scenarioConcatRenderer->concatIfPossible($projectId, $project);
        $payload->scenarioConcat = $scenarioConcat;

        $this->sortSceneClipReportsForSummary($payload);

        $this->renderingSummaryWriter->write(
            $this->projectSetup->getRenderOutputDir($projectId),
            $payload->sceneClipReports,
            $scenarioConcat,
        );

        $project->setRendering(VideoRenderingMetadata::projectRenderingFromScenario($scenarioConcat));
        $this->projectRepository->save($project);

        $renderOutputPath = null;
        if ($project->status()->value === 'completed') {
            $renderOutputPath = $this->renderer->render(
                $project,
                $this->projectSetup->getRenderOutputPath($projectId)
            );
            $this->projectRepository->save($project);
        }

        $payload->renderOutputPath = $renderOutputPath;
        $payload->result = new VideoGenerationResult(
            $project,
            $renderOutputPath,
            $benchmarkReportPaths,
            $scenarioConcat->outputPath,
            $scenarioConcat->skipReason,
        );

        return $payload;
    }

    /**
     * Deterministic scene order in rendering-summary.json (matches scenario concat ordering).
     */
    private function sortSceneClipReportsForSummary(VideoGenerationPayload $payload): void
    {
        if ($payload->sceneClipReports === []) {
            return;
        }

        SceneClipRenderReport::sortBySceneNumber($payload->sceneClipReports);
    }
}

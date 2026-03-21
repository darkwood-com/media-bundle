<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Trailer\DTO\TrailerGenerationResult;
use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Application\Trailer\Port\TrailerRendererInterface;
use App\Flow\Model\TrailerGenerationPayload;
use App\Infrastructure\Trailer\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Trailer\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Trailer\Rendering\TrailerRenderingMetadata;
use App\Infrastructure\Trailer\Rendering\VideoBenchmarkReportWriter;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Flow step: finalize project status, benchmark reports, scenario concat, rendering summary, manifest render.
 *
 * @extends Flow<TrailerGenerationPayload, TrailerGenerationPayload>
 */
final class FinalizeTrailerProjectFlow extends Flow
{
    public function __construct(
        private readonly TrailerProjectRepositoryInterface $projectRepository,
        private readonly TrailerRendererInterface $renderer,
        private readonly VideoBenchmarkReportWriter $benchmarkReportWriter,
        private readonly ScenarioConcatFfmpegRenderer $scenarioConcatRenderer,
        private readonly RenderingSummaryJsonWriter $renderingSummaryWriter,
        private readonly LocalArtifactStorage $artifactStorage,
        private readonly TrailerProjectSetupInterface $projectSetup,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof TrailerGenerationPayload) {
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

    private function finalize(TrailerGenerationPayload $payload): TrailerGenerationPayload
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

        $this->renderingSummaryWriter->write(
            $this->projectSetup->getRenderOutputDir($projectId),
            $payload->sceneClipReports,
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

        $payload->renderOutputPath = $renderOutputPath;
        $payload->result = new TrailerGenerationResult(
            $project,
            $renderOutputPath,
            $benchmarkReportPaths,
            $scenarioConcat->outputPath,
            $scenarioConcat->skipReason,
        );

        return $payload;
    }
}

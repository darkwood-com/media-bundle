<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Trailer\Port\TrailerDefinitionLoaderInterface;
use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Application\Trailer\Port\TrailerProjectSetupInterface;
use App\Application\Trailer\Port\TrailerRendererInterface;
use App\Infrastructure\Trailer\Persistence\JsonTrailerProjectMapper;
use App\Infrastructure\Trailer\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Trailer\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Trailer\Rendering\VideoBenchmarkReportWriter;
use App\Infrastructure\Trailer\Storage\LocalArtifactStorage;
use Flow\Driver\FiberDriver;
use Flow\FlowFactory;
use Flow\FlowInterface;

/**
 * Composes trailer flows with a shared {@see FiberDriver} (same composition pattern as the article MCP app:
 * FlowFactory + yielded steps + shared driver).
 * Exposes the full pipeline and a single-scene Flow for async-friendly dispatch.
 */
final class TrailerGenerationFlowFactory
{
    private readonly FiberDriver $driver;

    public function __construct(
        private readonly TrailerDefinitionLoaderInterface $definitionLoader,
        private readonly TrailerProjectRepositoryInterface $projectRepository,
        private readonly TrailerProjectSetupInterface $projectSetup,
        private readonly TrailerSceneStep $sceneStep,
        private readonly JsonTrailerProjectMapper $projectMapper,
        private readonly int $maxParallelScenes,
        private readonly TrailerRendererInterface $renderer,
        private readonly VideoBenchmarkReportWriter $benchmarkReportWriter,
        private readonly ScenarioConcatFfmpegRenderer $scenarioConcatRenderer,
        private readonly RenderingSummaryJsonWriter $renderingSummaryWriter,
        private readonly LocalArtifactStorage $artifactStorage,
    ) {
        $this->driver = new FiberDriver();
    }

    /**
     * Prepare → process scenes → finalize; sequential composition via FlowFactory.
     */
    public function createPipeline(): FlowInterface
    {
        $driver = $this->driver;

        return (new FlowFactory())->create(function () use ($driver) {
            yield new PrepareTrailerProjectFlow(
                $this->definitionLoader,
                $this->projectRepository,
                $this->projectSetup,
                $driver,
            );
            yield new ProcessTrailerScenesFlow(
                $this->sceneStep,
                $this->projectMapper,
                $this->projectRepository,
                $this->maxParallelScenes,
                $driver,
            );
            yield new FinalizeTrailerProjectFlow(
                $this->projectRepository,
                $this->renderer,
                $this->benchmarkReportWriter,
                $this->scenarioConcatRenderer,
                $this->renderingSummaryWriter,
                $this->artifactStorage,
                $this->projectSetup,
                $driver,
            );
        }, ['driver' => $driver]);
    }

    /**
     * One scene per Ip({@see TrailerScenePayload}); await after one or more pushes for async orchestration.
     */
    public function createSceneGenerationFlow(): FlowInterface
    {
        return new TrailerSceneGenerationFlow($this->sceneStep, $this->driver);
    }

    public function getDriver(): FiberDriver
    {
        return $this->driver;
    }
}

<?php

declare(strict_types=1);

namespace App\Flow;

use App\Application\Video\Port\VideoDefinitionLoaderInterface;
use App\Application\Video\Port\VideoProjectRepositoryInterface;
use App\Application\Video\Port\VideoProjectSetupInterface;
use App\Application\Video\Port\VideoRendererInterface;
use App\Infrastructure\Video\Persistence\JsonVideoProjectMapper;
use App\Infrastructure\Video\Rendering\RenderingSummaryJsonWriter;
use App\Infrastructure\Video\Rendering\ScenarioConcatFfmpegRenderer;
use App\Infrastructure\Video\Rendering\VideoBenchmarkReportWriter;
use App\Infrastructure\Video\Storage\LocalArtifactStorage;
use Flow\Driver\FiberDriver;
use Flow\FlowFactory;
use Flow\FlowInterface;

/**
 * Composes video flows with a shared {@see FiberDriver} (same composition pattern as the article MCP app:
 * FlowFactory + yielded steps + shared driver).
 * Exposes the full pipeline and a single-scene Flow for async-friendly dispatch.
 */
final class VideoGenerationFlowFactory
{
    private readonly FiberDriver $driver;

    public function __construct(
        private readonly VideoDefinitionLoaderInterface $definitionLoader,
        private readonly VideoProjectRepositoryInterface $projectRepository,
        private readonly VideoProjectSetupInterface $projectSetup,
        private readonly VideoSceneStep $sceneStep,
        private readonly JsonVideoProjectMapper $projectMapper,
        private readonly int $maxParallelScenes,
        private readonly VideoRendererInterface $renderer,
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
            yield new PrepareVideoProjectFlow(
                $this->definitionLoader,
                $this->projectRepository,
                $this->projectSetup,
                $driver,
            );
            yield new ProcessVideoScenesFlow(
                $this->sceneStep,
                $this->projectMapper,
                $this->projectRepository,
                $this->maxParallelScenes,
                $driver,
            );
            yield new FinalizeVideoProjectFlow(
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
     * One scene per Ip({@see VideoScenePayload}); await after one or more pushes for async orchestration.
     */
    public function createSceneGenerationFlow(): FlowInterface
    {
        return new VideoSceneGenerationFlow($this->sceneStep, $this->driver);
    }

    public function getDriver(): FiberDriver
    {
        return $this->driver;
    }
}

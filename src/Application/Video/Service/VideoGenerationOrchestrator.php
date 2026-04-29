<?php

declare(strict_types=1);

namespace App\Application\Video\Service;

use App\Application\Video\DTO\VideoGenerationResult;
use App\Application\Video\Port\VideoGenerationOrchestratorInterface;
use App\Flow\Model\VideoGenerationPayload;
use App\Flow\VideoGenerationFlowFactory;
use Flow\Ip;

/**
 * Parent orchestration facade: runs video generation through a composed Flow pipeline
 * (prepare → scenes → finalize) while preserving existing domain services and CLI entrypoints.
 */
final class VideoGenerationOrchestrator implements VideoGenerationOrchestratorInterface
{
    public function __construct(
        private readonly VideoGenerationFlowFactory $flowFactory,
    ) {
    }

    /**
     * @param array<string, mixed>|null $firstSceneVideoOptions Passed to the video provider for scene 1 only.
     *        Use replicate_preset / replicate_model for a single clip, or replicate_benchmark_presets (list of preset keys)
     *        for scene-1 video-only benchmark: same prompt, multiple outputs; voice is skipped for that scene.
     */
    public function generateFromYaml(string $yamlPath, ?array $firstSceneVideoOptions = null): VideoGenerationResult
    {
        $payload = new VideoGenerationPayload($yamlPath, $firstSceneVideoOptions);
        $ip = new Ip($payload);
        $flow = $this->flowFactory->createPipeline();
        $flow($ip);
        $flow->await();

        return $payload->getResult();
    }
}

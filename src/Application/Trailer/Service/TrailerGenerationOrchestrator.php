<?php

declare(strict_types=1);

namespace App\Application\Trailer\Service;

use App\Application\Trailer\DTO\TrailerGenerationResult;
use App\Application\Trailer\Port\TrailerGenerationOrchestratorInterface;
use App\Flow\Model\TrailerGenerationPayload;
use App\Flow\TrailerGenerationFlowFactory;
use Flow\Ip;

/**
 * Parent orchestration facade: runs trailer generation through a composed Flow pipeline
 * (prepare → scenes → finalize) while preserving existing domain services and CLI entrypoints.
 */
final class TrailerGenerationOrchestrator implements TrailerGenerationOrchestratorInterface
{
    public function __construct(
        private readonly TrailerGenerationFlowFactory $flowFactory,
    ) {
    }

    /**
     * @param array<string, mixed>|null $firstSceneVideoOptions Passed to the video provider for scene 1 only.
     *        Use replicate_preset / replicate_model for a single clip, or replicate_benchmark_presets (list of preset keys)
     *        for scene-1 video-only benchmark: same prompt, multiple outputs; voice is skipped for that scene.
     */
    public function generateFromYaml(string $yamlPath, ?array $firstSceneVideoOptions = null): TrailerGenerationResult
    {
        $payload = new TrailerGenerationPayload($yamlPath, $firstSceneVideoOptions);
        $ip = new Ip($payload);
        $flow = $this->flowFactory->createPipeline();
        $flow($ip);
        $flow->await();

        return $payload->getResult();
    }
}

<?php

declare(strict_types=1);

namespace App\Flow;

use App\Flow\Model\TrailerGenerationPayload;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Flow step: iterate scenes and delegate to {@see TrailerSceneStep}.
 *
 * @extends Flow<TrailerGenerationPayload, TrailerGenerationPayload>
 */
final class ProcessTrailerScenesFlow extends Flow
{
    public function __construct(
        private readonly TrailerSceneStep $sceneStep,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof TrailerGenerationPayload) {
                return $payload;
            }

            return $this->processScenes($payload);
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

    private function processScenes(TrailerGenerationPayload $payload): TrailerGenerationPayload
    {
        $project = $payload->project;
        if ($project === null) {
            return $payload;
        }

        foreach ($project->scenes() as $index => $_scene) {
            $this->sceneStep->processForGeneration($payload, $index);
        }

        return $payload;
    }
}

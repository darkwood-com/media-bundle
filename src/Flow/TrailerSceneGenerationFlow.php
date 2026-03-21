<?php

declare(strict_types=1);

namespace App\Flow;

use App\Flow\Model\TrailerScenePayload;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Standalone Flow for one scene: same business logic as {@see TrailerSceneStep}, wrapped as a Flow step.
 * Parent orchestration can push an {@see \Flow\Ip} with {@see TrailerScenePayload} and await this flow
 * to dispatch scene work asynchronously (e.g. separate worker or concurrent scheduling at the caller).
 *
 * @extends Flow<TrailerScenePayload, TrailerScenePayload>
 */
final class TrailerSceneGenerationFlow extends Flow
{
    public function __construct(
        private readonly TrailerSceneStep $sceneStep,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof TrailerScenePayload) {
                return $payload;
            }

            return $this->sceneStep->process($payload);
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
}

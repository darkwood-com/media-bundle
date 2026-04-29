<?php

declare(strict_types=1);

namespace App\Flow;

use App\Flow\Model\VideoScenePayload;
use Flow\AsyncHandler\AsyncHandler;
use Flow\Driver\FiberDriver;
use Flow\DriverInterface;
use Flow\Flow\Flow;
use Flow\IpStrategy\LinearIpStrategy;

/**
 * Standalone Flow for one scene: same business logic as {@see VideoSceneStep}, wrapped as a Flow step.
 * Parent orchestration can push an {@see \Flow\Ip} with {@see VideoScenePayload} and await this flow
 * to dispatch scene work asynchronously (e.g. separate worker or concurrent scheduling at the caller).
 *
 * @extends Flow<VideoScenePayload, VideoScenePayload>
 */
final class VideoSceneGenerationFlow extends Flow
{
    public function __construct(
        private readonly VideoSceneStep $sceneStep,
        ?DriverInterface $driver = null,
    ) {
        $job = function (mixed $payload): mixed {
            if (!$payload instanceof VideoScenePayload) {
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

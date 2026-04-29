<?php

declare(strict_types=1);

namespace App\Application\Video\Exception;

class SceneNotFoundException extends \InvalidArgumentException
{
    public function __construct(string $projectId, string $sceneId)
    {
        parent::__construct(sprintf('Scene "%s" not found in project "%s".', $sceneId, $projectId));
    }
}

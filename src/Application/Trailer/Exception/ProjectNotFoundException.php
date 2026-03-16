<?php

declare(strict_types=1);

namespace App\Application\Trailer\Exception;

class ProjectNotFoundException extends \InvalidArgumentException
{
    public function __construct(string $projectId)
    {
        parent::__construct(sprintf('Project not found: "%s".', $projectId));
    }
}

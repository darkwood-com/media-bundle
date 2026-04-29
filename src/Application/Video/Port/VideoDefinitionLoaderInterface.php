<?php

declare(strict_types=1);

namespace App\Application\Video\Port;

use App\Application\Video\DTO\VideoDefinition;

interface VideoDefinitionLoaderInterface
{
    /**
     * Load and validate a video definition from the given path.
     *
     * @throws \App\Application\Video\Exception\InvalidVideoDefinitionException
     */
    public function load(string $path): VideoDefinition;
}

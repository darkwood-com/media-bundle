<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

use App\Application\Trailer\DTO\TrailerDefinition;

interface TrailerDefinitionLoaderInterface
{
    /**
     * Load and validate a trailer definition from the given path.
     *
     * @throws \App\Application\Trailer\Exception\InvalidTrailerDefinitionException
     */
    public function load(string $path): TrailerDefinition;
}

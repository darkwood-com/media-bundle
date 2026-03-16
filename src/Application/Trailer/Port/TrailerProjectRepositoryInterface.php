<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

use App\Domain\Trailer\TrailerProject;

interface TrailerProjectRepositoryInterface
{
    public function get(string $id): ?TrailerProject;

    public function save(TrailerProject $project): void;
}

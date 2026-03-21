<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;

interface TrailerProjectRepositoryInterface
{
    public function get(string $id): ?TrailerProject;

    public function save(TrailerProject $project): void;

    /**
     * Read-modify-write project.json under an exclusive lock: replace one scene by index.
     * Used when scenes complete independently (e.g. parallel workers) without overwriting other scenes.
     */
    public function mergeSceneAtIndex(string $projectId, int $index, Scene $scene): void;
}

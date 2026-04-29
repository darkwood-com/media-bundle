<?php

declare(strict_types=1);

namespace App\Application\Video\Port;

use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;

interface VideoProjectRepositoryInterface
{
    public function get(string $id): ?VideoProject;

    public function save(VideoProject $project): void;

    /**
     * Read-modify-write project.json under an exclusive lock: replace one scene by index.
     * Used when scenes complete independently (e.g. parallel workers) without overwriting other scenes.
     */
    public function mergeSceneAtIndex(string $projectId, int $index, Scene $scene): void;
}

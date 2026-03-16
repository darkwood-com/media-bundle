<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Persistence;

use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Domain\Trailer\TrailerProject;

final class FileTrailerProjectRepository implements TrailerProjectRepositoryInterface
{
    private const PROJECT_FILE = 'project.json';

    public function __construct(
        private string $projectDir,
        private JsonTrailerProjectMapper $mapper,
    ) {
    }

    public function get(string $id): ?TrailerProject
    {
        $path = $this->projectFilePath($id);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        return $this->mapper->fromArray($data);
    }

    public function save(TrailerProject $project): void
    {
        $path = $this->projectFilePath($project->id());
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $data = $this->mapper->toArray($project);
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        file_put_contents($path, $json, \LOCK_EX);
    }

    private function projectFilePath(string $projectId): string
    {
        $base = rtrim($this->projectDir, \DIRECTORY_SEPARATOR);
        return $base . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'trailers' . \DIRECTORY_SEPARATOR . $projectId . \DIRECTORY_SEPARATOR . self::PROJECT_FILE;
    }
}

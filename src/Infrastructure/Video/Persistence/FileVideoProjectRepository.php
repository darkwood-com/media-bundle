<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Persistence;

use App\Application\Video\Port\VideoProjectRepositoryInterface;
use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;

final class FileVideoProjectRepository implements VideoProjectRepositoryInterface
{
    private const PROJECT_FILE = 'project.json';

    public function __construct(
        private string $projectDir,
        private JsonVideoProjectMapper $mapper,
    ) {
    }

    public function get(string $id): ?VideoProject
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

    public function save(VideoProject $project): void
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

    public function mergeSceneAtIndex(string $projectId, int $index, Scene $scene): void
    {
        $path = $this->projectFilePath($projectId);
        $fp = fopen($path, 'r+');
        if ($fp === false) {
            throw new \RuntimeException(sprintf('Cannot open project file for scene merge: %s', $path));
        }

        if (!flock($fp, \LOCK_EX)) {
            fclose($fp);
            throw new \RuntimeException(sprintf('Cannot lock project file for scene merge: %s', $path));
        }

        try {
            $raw = stream_get_contents($fp);
            if ($raw === false) {
                $raw = '';
            }
            if ($raw === '') {
                throw new \RuntimeException(sprintf('Project file is empty: %s', $path));
            }

            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException(sprintf('Project file is not a JSON object: %s', $path));
            }

            $project = $this->mapper->fromArray($data);
            $this->mapper->replaceSceneAtIndex($project, $index, $scene);
            $json = json_encode($this->mapper->toArray($project), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

            rewind($fp);
            if (!ftruncate($fp, 0)) {
                throw new \RuntimeException(sprintf('Cannot truncate project file: %s', $path));
            }

            $written = fwrite($fp, $json);
            if ($written === false || $written !== \strlen($json)) {
                throw new \RuntimeException(sprintf('Failed to write merged project file: %s', $path));
            }

            fflush($fp);
        } finally {
            flock($fp, \LOCK_UN);
            fclose($fp);
        }
    }

    private function projectFilePath(string $projectId): string
    {
        $base = rtrim($this->projectDir, \DIRECTORY_SEPARATOR);
        return $base . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR . 'videos' . \DIRECTORY_SEPARATOR . $projectId . \DIRECTORY_SEPARATOR . self::PROJECT_FILE;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Loader;

use App\Application\Video\DTO\SceneDefinition;
use App\Application\Video\DTO\VideoDefinition;
use App\Application\Video\Exception\InvalidVideoDefinitionException;
use App\Application\Video\Port\VideoDefinitionLoaderInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlVideoDefinitionLoader implements VideoDefinitionLoaderInterface
{
    public function load(string $path): VideoDefinition
    {
        if (!is_readable($path)) {
            throw InvalidVideoDefinitionException::parseError($path, 'File is not readable or does not exist.');
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw InvalidVideoDefinitionException::parseError($path, $e->getMessage());
        }

        if (!is_array($data)) {
            throw InvalidVideoDefinitionException::parseError($path, 'Root must be a YAML mapping (associative array).');
        }

        return $this->buildVideoDefinition($path, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildVideoDefinition(string $path, array $data): VideoDefinition
    {
        if (!array_key_exists('title', $data)) {
            throw InvalidVideoDefinitionException::missingKey('title');
        }
        if (!is_string($data['title']) || $data['title'] === '') {
            throw InvalidVideoDefinitionException::invalidType('title', 'a non-empty string');
        }

        if (!array_key_exists('scenes', $data)) {
            throw InvalidVideoDefinitionException::missingKey('scenes');
        }
        if (!is_array($data['scenes'])) {
            throw InvalidVideoDefinitionException::invalidType('scenes', 'an array');
        }

        $scenes = [];
        foreach ($data['scenes'] as $index => $sceneData) {
            if (!is_array($sceneData)) {
                throw InvalidVideoDefinitionException::invalidScene(
                    $index,
                    'each scene must be a mapping (associative array).'
                );
            }
            $scenes[] = $this->buildSceneDefinition($index, $sceneData);
        }

        return new VideoDefinition($data['title'], $scenes);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildSceneDefinition(int $index, array $data): SceneDefinition
    {
        if (!array_key_exists('id', $data)) {
            throw InvalidVideoDefinitionException::invalidScene($index, 'missing required key "id".');
        }
        if (!is_string($data['id']) || $data['id'] === '') {
            throw InvalidVideoDefinitionException::invalidScene($index, '"id" must be a non-empty string.');
        }

        if (!array_key_exists('title', $data)) {
            throw InvalidVideoDefinitionException::invalidScene($index, 'missing required key "title".');
        }
        if (!is_string($data['title'])) {
            throw InvalidVideoDefinitionException::invalidScene($index, '"title" must be a string.');
        }

        $description = isset($data['description']) && is_string($data['description']) ? $data['description'] : '';
        $videoPrompt = isset($data['video_prompt']) && is_string($data['video_prompt']) ? $data['video_prompt'] : '';
        $narration = isset($data['narration']) && is_string($data['narration']) ? $data['narration'] : '';

        $duration = null;
        if (array_key_exists('duration', $data)) {
            if (is_int($data['duration']) || is_float($data['duration'])) {
                $duration = (float) $data['duration'];
            } elseif (is_numeric($data['duration'])) {
                $duration = (float) $data['duration'];
            } else {
                throw InvalidVideoDefinitionException::invalidScene($index, '"duration" must be a number.');
            }
        }

        return new SceneDefinition(
            id: $data['id'],
            title: $data['title'],
            description: $description,
            videoPrompt: $videoPrompt,
            narration: $narration,
            duration: $duration,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Loader;

use App\Application\Trailer\DTO\SceneDefinition;
use App\Application\Trailer\DTO\TrailerDefinition;
use App\Application\Trailer\Exception\InvalidTrailerDefinitionException;
use App\Application\Trailer\Port\TrailerDefinitionLoaderInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlTrailerDefinitionLoader implements TrailerDefinitionLoaderInterface
{
    public function load(string $path): TrailerDefinition
    {
        if (!is_readable($path)) {
            throw InvalidTrailerDefinitionException::parseError($path, 'File is not readable or does not exist.');
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw InvalidTrailerDefinitionException::parseError($path, $e->getMessage());
        }

        if (!is_array($data)) {
            throw InvalidTrailerDefinitionException::parseError($path, 'Root must be a YAML mapping (associative array).');
        }

        return $this->buildTrailerDefinition($path, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildTrailerDefinition(string $path, array $data): TrailerDefinition
    {
        if (!array_key_exists('title', $data)) {
            throw InvalidTrailerDefinitionException::missingKey('title');
        }
        if (!is_string($data['title']) || $data['title'] === '') {
            throw InvalidTrailerDefinitionException::invalidType('title', 'a non-empty string');
        }

        if (!array_key_exists('scenes', $data)) {
            throw InvalidTrailerDefinitionException::missingKey('scenes');
        }
        if (!is_array($data['scenes'])) {
            throw InvalidTrailerDefinitionException::invalidType('scenes', 'an array');
        }

        $scenes = [];
        foreach ($data['scenes'] as $index => $sceneData) {
            if (!is_array($sceneData)) {
                throw InvalidTrailerDefinitionException::invalidScene(
                    $index,
                    'each scene must be a mapping (associative array).'
                );
            }
            $scenes[] = $this->buildSceneDefinition($index, $sceneData);
        }

        return new TrailerDefinition($data['title'], $scenes);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildSceneDefinition(int $index, array $data): SceneDefinition
    {
        if (!array_key_exists('id', $data)) {
            throw InvalidTrailerDefinitionException::invalidScene($index, 'missing required key "id".');
        }
        if (!is_string($data['id']) || $data['id'] === '') {
            throw InvalidTrailerDefinitionException::invalidScene($index, '"id" must be a non-empty string.');
        }

        if (!array_key_exists('title', $data)) {
            throw InvalidTrailerDefinitionException::invalidScene($index, 'missing required key "title".');
        }
        if (!is_string($data['title'])) {
            throw InvalidTrailerDefinitionException::invalidScene($index, '"title" must be a string.');
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
                throw InvalidTrailerDefinitionException::invalidScene($index, '"duration" must be a number.');
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

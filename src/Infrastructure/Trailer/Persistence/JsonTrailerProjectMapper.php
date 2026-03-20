<?php

declare(strict_types=1);

namespace App\Infrastructure\Trailer\Persistence;

use App\Domain\Trailer\Asset;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Enum\ProjectStatus;
use App\Domain\Trailer\Enum\SceneStatus;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;

/**
 * Maps TrailerProject domain model to/from JSON-friendly array structure.
 * Uses reflection to hydrate private scene/asset collections for reconstitution.
 *
 * @internal
 */
final class JsonTrailerProjectMapper
{
    private const DATE_FORMAT = \DateTimeInterface::ATOM;

    /**
     * @return array<string, mixed>
     */
    public function toArray(TrailerProject $project): array
    {
        return [
            'id' => $project->id(),
            'title' => $project->title(),
            'sourceScenarioPath' => $project->sourceScenarioPath(),
            'status' => $project->status()->value,
            'createdAt' => $project->createdAt()->format(self::DATE_FORMAT),
            'updatedAt' => $project->updatedAt()->format(self::DATE_FORMAT),
            'rendering' => $project->rendering(),
            'scenes' => array_map($this->sceneToArray(...), $project->scenes()),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): TrailerProject
    {
        $id = $this->string($data, 'id');
        $sourceScenarioPath = $this->string($data, 'sourceScenarioPath');
        $title = $this->string($data, 'title');
        $status = $this->enum($data, 'status', ProjectStatus::class, ProjectStatus::Draft);
        $createdAt = $this->date($data, 'createdAt');
        $updatedAt = $this->date($data, 'updatedAt');

        $project = new TrailerProject(
            id: $id,
            sourceScenarioPath: $sourceScenarioPath,
            title: $title,
            status: $status,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
            rendering: $this->projectRenderingFromData($data),
        );

        $scenesData = $data['scenes'] ?? [];
        if (!is_array($scenesData)) {
            return $project;
        }

        $scenes = [];
        foreach ($scenesData as $sceneData) {
            if (!is_array($sceneData)) {
                continue;
            }
            $scenes[] = $this->sceneFromArray($sceneData);
        }

        $this->setPrivateProperty($project, 'scenes', $scenes);

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneToArray(Scene $scene): array
    {
        return [
            'id' => $scene->id(),
            'number' => $scene->number(),
            'title' => $scene->title(),
            'description' => $scene->description(),
            'videoPrompt' => $scene->videoPrompt(),
            'narrationText' => $scene->narrationText(),
            'duration' => $scene->duration(),
            'status' => $scene->status()->value,
            'lastError' => $scene->lastError(),
            'clipRender' => $scene->clipRender(),
            'assets' => array_map($this->assetToArray(...), $scene->assets()),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sceneFromArray(array $data): Scene
    {
        $scene = new Scene(
            id: $this->string($data, 'id'),
            number: $this->int($data, 'number'),
            title: $this->string($data, 'title'),
            description: $this->string($data, 'description') ?? '',
            videoPrompt: $this->string($data, 'videoPrompt') ?? '',
            narrationText: $this->string($data, 'narrationText') ?? '',
            duration: $this->floatOrNull($data, 'duration'),
            status: $this->enum($data, 'status', SceneStatus::class, SceneStatus::Pending),
            lastError: $this->stringOrNull($data, 'lastError'),
            clipRender: $this->sceneClipRenderFromData($data),
        );

        $assetsData = $data['assets'] ?? [];
        if (!is_array($assetsData)) {
            return $scene;
        }

        $assets = [];
        foreach ($assetsData as $assetData) {
            if (!is_array($assetData)) {
                continue;
            }
            $assets[] = $this->assetFromArray($assetData);
        }

        $this->setPrivateProperty($scene, 'assets', $assets);

        return $scene;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetToArray(Asset $asset): array
    {
        return [
            'id' => $asset->id(),
            'sceneId' => $asset->sceneId(),
            'type' => $asset->type()->value,
            'status' => $asset->status()->value,
            'provider' => $asset->provider(),
            'path' => $asset->path(),
            'metadata' => $asset->metadata(),
            'lastError' => $asset->lastError(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assetFromArray(array $data): Asset
    {
        return new Asset(
            id: $this->string($data, 'id'),
            sceneId: $this->string($data, 'sceneId'),
            type: $this->enum($data, 'type', AssetType::class, AssetType::Video),
            status: $this->enum($data, 'status', AssetStatus::class, AssetStatus::Pending),
            provider: $this->stringOrNull($data, 'provider'),
            path: $this->stringOrNull($data, 'path'),
            metadata: $this->metadata($data),
            lastError: $this->stringOrNull($data, 'lastError'),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param class-string<\BackedEnum> $enumClass
     */
    private function enum(array $data, string $key, string $enumClass, \BackedEnum $default): \BackedEnum
    {
        $v = $data[$key] ?? null;
        if ($v === null || $v === '') {
            return $default;
        }
        $s = is_string($v) ? $v : (string) $v;
        try {
            return $enumClass::from($s);
        } catch (\ValueError) {
            return $default;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key): string
    {
        $v = $data[$key] ?? null;
        return is_string($v) ? $v : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringOrNull(array $data, string $key): ?string
    {
        $v = $data[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        return is_string($v) ? $v : (string) $v;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function int(array $data, string $key): int
    {
        $v = $data[$key] ?? 0;
        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function floatOrNull(array $data, string $key): ?float
    {
        $v = $data[$key] ?? null;
        if ($v === null) {
            return null;
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return \DateTimeImmutable
     */
    private function date(array $data, string $key): \DateTimeImmutable
    {
        $v = $data[$key] ?? null;
        if ($v instanceof \DateTimeImmutable) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $v)
                ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ISO8601, $v);
            if ($d !== false) {
                return $d;
            }
        }
        return new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function projectRenderingFromData(array $data): array
    {
        $v = $data['rendering'] ?? [];
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $val) {
            if (is_string($k)) {
                $out[$k] = $val;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function sceneClipRenderFromData(array $data): array
    {
        $v = $data['clipRender'] ?? [];
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $val) {
            if (is_string($k)) {
                $out[$k] = $val;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function metadata(array $data): array
    {
        $v = $data['metadata'] ?? [];
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $val) {
            if (is_string($k)) {
                $out[$k] = $val;
            }
        }
        return $out;
    }

    private function setPrivateProperty(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setValue($object, $value);
    }
}

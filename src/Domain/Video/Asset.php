<?php

declare(strict_types=1);

namespace App\Domain\Video;

use App\Domain\Video\Enum\AssetStatus;
use App\Domain\Video\Enum\AssetType;

final class Asset
{
    public function __construct(
        private string $id,
        private string $sceneId,
        private AssetType $type,
        private AssetStatus $status,
        private ?string $provider = null,
        private ?string $path = null,
        /** @var array<string, mixed> */
        private array $metadata = [],
        private ?string $lastError = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function sceneId(): string
    {
        return $this->sceneId;
    }

    public function type(): AssetType
    {
        return $this->type;
    }

    public function status(): AssetStatus
    {
        return $this->status;
    }

    public function provider(): ?string
    {
        return $this->provider;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function markProcessing(?string $provider = null): void
    {
        $this->status = AssetStatus::Processing;
        if ($provider !== null) {
            $this->provider = $provider;
        }
        $this->lastError = null;
    }

    public function complete(string $path, array $metadata = []): void
    {
        $this->status = AssetStatus::Completed;
        $this->path = $path;
        $this->metadata = array_merge($this->metadata, $metadata);
        $this->lastError = null;

        $p = $this->metadata['provider'] ?? null;
        if (is_string($p) && $p !== '') {
            $this->provider = $p;
        }
    }

    public function fail(string $error): void
    {
        $this->status = AssetStatus::Failed;
        $this->lastError = $error;
    }

    public function updatePath(string $path): void
    {
        $this->path = $path;
    }

    /** @param array<string, mixed> $metadata */
    public function updateMetadata(array $metadata): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
    }
}

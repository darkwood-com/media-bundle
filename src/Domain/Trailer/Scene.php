<?php

declare(strict_types=1);

namespace App\Domain\Trailer;

use App\Domain\Trailer\Enum\SceneStatus;

final class Scene
{
    /** @var list<Asset> */
    private array $assets = [];

    public function __construct(
        private string $id,
        private int $number,
        private string $title,
        private string $description = '',
        private string $videoPrompt = '',
        private string $narrationText = '',
        private ?float $duration = null,
        private SceneStatus $status = SceneStatus::Pending,
        private ?string $lastError = null,
        /** @var array<string, mixed> Persisted scene.mp4 render outcome (see TrailerRenderingMetadata). */
        private array $clipRender = [],
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function videoPrompt(): string
    {
        return $this->videoPrompt;
    }

    public function narrationText(): string
    {
        return $this->narrationText;
    }

    public function duration(): ?float
    {
        return $this->duration;
    }

    public function status(): SceneStatus
    {
        return $this->status;
    }

    /** @return list<Asset> */
    public function assets(): array
    {
        return $this->assets;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array<string, mixed>
     */
    public function clipRender(): array
    {
        return $this->clipRender;
    }

    /**
     * @param array<string, mixed> $clipRender
     */
    public function setClipRender(array $clipRender): void
    {
        $this->clipRender = $clipRender;
    }

    public function addAsset(Asset $asset): void
    {
        $this->assets[] = $asset;
    }

    public function markProcessing(): void
    {
        $this->status = SceneStatus::Processing;
        $this->lastError = null;
    }

    public function complete(): void
    {
        $this->status = SceneStatus::Completed;
        $this->lastError = null;
    }

    public function fail(string $error): void
    {
        $this->status = SceneStatus::Failed;
        $this->lastError = $error;
    }

    public function setDuration(float $duration): void
    {
        $this->duration = $duration;
    }

    /**
     * Reset scene for rerun: clear assets, set status to Pending.
     * Used when regenerating a single scene from a saved project.
     */
    public function resetForRerun(): void
    {
        $this->assets = [];
        $this->status = SceneStatus::Pending;
        $this->lastError = null;
        $this->duration = null;
        $this->clipRender = [];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Video;

use App\Domain\Video\Enum\ProjectStatus;

final class VideoProject
{
    /** @var list<Scene> */
    private array $scenes = [];

    public function __construct(
        private string $id,
        private string $sourceScenarioPath,
        private string $title,
        private ProjectStatus $status = ProjectStatus::Draft,
        private ?\DateTimeImmutable $createdAt = null,
        private ?\DateTimeImmutable $updatedAt = null,
        /** @var array<string, mixed> Persisted scenario.mp4 concat summary (see VideoRenderingMetadata). */
        private array $rendering = [],
    ) {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt ??= $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function sourceScenarioPath(): string
    {
        return $this->sourceScenarioPath;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function status(): ProjectStatus
    {
        return $this->status;
    }

    /** @return list<Scene> */
    public function scenes(): array
    {
        return $this->scenes;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function rendering(): array
    {
        return $this->rendering;
    }

    /**
     * @param array<string, mixed> $rendering
     */
    public function setRendering(array $rendering): void
    {
        $this->rendering = $rendering;
        $this->touch();
    }

    public function addScene(Scene $scene): void
    {
        $this->scenes[] = $scene;
        $this->touch();
    }

    public function startProcessing(): void
    {
        $this->status = ProjectStatus::Processing;
        $this->touch();
    }

    public function complete(): void
    {
        $this->status = ProjectStatus::Completed;
        $this->touch();
    }

    public function fail(): void
    {
        $this->status = ProjectStatus::Failed;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

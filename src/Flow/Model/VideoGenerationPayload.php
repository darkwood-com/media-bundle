<?php

declare(strict_types=1);

namespace App\Flow\Model;

use App\Application\Video\DTO\VideoDefinition;
use App\Application\Video\DTO\VideoGenerationResult;
use App\Domain\Video\VideoProject;
use App\Infrastructure\Video\Rendering\ScenarioConcatResult;
use App\Infrastructure\Video\Rendering\SceneClipRenderReport;

/**
 * Mutable Ip payload for video generation flows. Mutated in place through prepare,
 * scene processing, and finalize steps (same pattern as GenerateDraftPayload in Flow examples).
 */
final class VideoGenerationPayload
{
    /** @var list<SceneClipRenderReport> */
    public array $sceneClipReports = [];

    public bool $anyFailed = false;

    /** @var array{json: string, markdown: string}|null */
    public ?array $benchmarkReportPaths = null;

    public ?ScenarioConcatResult $scenarioConcat = null;

    public ?string $renderOutputPath = null;

    public ?VideoGenerationResult $result = null;

    public function __construct(
        public string $yamlPath,
        /** @var array<string, mixed>|null */
        public ?array $firstSceneVideoOptions = null,
        public ?VideoDefinition $definition = null,
        public ?VideoProject $project = null,
        public string $projectId = '',
    ) {
    }

    public function getResult(): VideoGenerationResult
    {
        if ($this->result === null) {
            throw new \LogicException('Video generation did not produce a result (finalize step missing or failed).');
        }

        return $this->result;
    }
}

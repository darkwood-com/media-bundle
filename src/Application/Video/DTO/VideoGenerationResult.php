<?php

declare(strict_types=1);

namespace App\Application\Video\DTO;

use App\Domain\Video\VideoProject;

final readonly class VideoGenerationResult
{
    /**
     * @param array{json: string, markdown: string}|null $benchmarkReportPaths
     */
    public function __construct(
        public VideoProject $project,
        public ?string $renderOutputPath = null,
        public ?array $benchmarkReportPaths = null,
        public ?string $scenarioOutputPath = null,
        public ?string $scenarioSkipReason = null,
    ) {
    }
}

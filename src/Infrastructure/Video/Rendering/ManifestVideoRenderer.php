<?php

declare(strict_types=1);

namespace App\Infrastructure\Video\Rendering;

use App\Application\Video\Port\VideoRendererInterface;
use App\Domain\Video\Asset;
use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;

/**
 * MVP renderer: writes a structured JSON manifest instead of a video.
 * Output: var/videos/<project-id>/render/video-manifest.json
 */
final class ManifestVideoRenderer implements VideoRendererInterface
{
    private const MANIFEST_FILENAME = 'video-manifest.json';

    public function render(VideoProject $project, string $outputPath): string
    {
        $renderDir = \dirname($outputPath);
        $manifestPath = $renderDir . '/' . self::MANIFEST_FILENAME;

        $manifest = $this->buildManifest($project);
        $json = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        if (!is_dir($renderDir)) {
            mkdir($renderDir, 0755, true);
        }
        file_put_contents($manifestPath, $json);

        return $manifestPath;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(VideoProject $project): array
    {
        $scenes = [];
        $totalDuration = 0.0;
        $hasAnyDuration = false;

        foreach ($project->scenes() as $scene) {
            $sceneData = $this->sceneToArray($scene);
            $scenes[] = $sceneData;
            if ($scene->duration() !== null) {
                $totalDuration += $scene->duration();
                $hasAnyDuration = true;
            }
        }

        return [
            'project_id' => $project->id(),
            'title' => $project->title(),
            'project_status' => $project->status()->value,
            'scenes' => $scenes,
            'duration' => $hasAnyDuration ? round($totalDuration, 2) : null,
            'narration' => $this->collectNarration($project),
            'asset_paths' => $this->collectAssetPaths($project),
            'asset_statuses' => $this->collectAssetStatuses($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneToArray(Scene $scene): array
    {
        $assets = [];
        foreach ($scene->assets() as $asset) {
            $assets[] = [
                'id' => $asset->id(),
                'type' => $asset->type()->value,
                'status' => $asset->status()->value,
                'path' => $asset->path(),
            ];
        }

        return [
            'id' => $scene->id(),
            'number' => $scene->number(),
            'title' => $scene->title(),
            'status' => $scene->status()->value,
            'duration' => $scene->duration(),
            'narration' => $scene->narrationText(),
            'assets' => $assets,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectNarration(VideoProject $project): array
    {
        $narration = [];
        foreach ($project->scenes() as $scene) {
            $narration[] = $scene->narrationText();
        }
        return $narration;
    }

    /**
     * @return list<string|null>
     */
    private function collectAssetPaths(VideoProject $project): array
    {
        $paths = [];
        foreach ($project->scenes() as $scene) {
            foreach ($scene->assets() as $asset) {
                $paths[] = $asset->path();
            }
        }
        return $paths;
    }

    /**
     * @return list<string>
     */
    private function collectAssetStatuses(VideoProject $project): array
    {
        $statuses = [];
        foreach ($project->scenes() as $scene) {
            foreach ($scene->assets() as $asset) {
                $statuses[] = $asset->status()->value;
            }
        }
        return $statuses;
    }
}

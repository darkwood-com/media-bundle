<?php

declare(strict_types=1);

namespace App\Application\Trailer\Port;

/**
 * Project-level setup: prepare directories, copy input definition, resolve render output path.
 */
interface TrailerProjectSetupInterface
{
    public function prepareProjectDirectories(string $projectId): void;

    /**
     * Copy input YAML into the project input folder.
     * Returns the full path of the copied file.
     */
    public function copyInputYaml(string $projectId, string $sourcePath): string;

    /**
     * Path where the final render output (e.g. final.mp4) should be written.
     */
    public function getRenderOutputPath(string $projectId): string;
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Video\DTO\VideoGenerationResult;
use App\Domain\Video\VideoProject;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Concise CLI summary for per-scene scene.mp4 rendering and scenario.mp4 concat.
 */
final class VideoRenderCliSummary
{
    public static function write(SymfonyStyle $io, VideoGenerationResult $result): void
    {
        self::writeSceneClipTable($io, $result->project);
        self::writeScenarioAndManifest($io, $result);
    }

    private static function writeSceneClipTable(SymfonyStyle $io, VideoProject $project): void
    {
        $io->writeln('');
        $io->section('Render — scene clips');

        $rows = [];
        foreach ($project->scenes() as $scene) {
            $clip = $scene->clipRender();
            $label = sprintf('%d · %s', $scene->number(), $scene->title());
            if ($clip === []) {
                $rows[] = [
                    OutputFormatter::escape($label),
                    '—',
                    '—',
                    '<comment>no render metadata</>',
                ];
                continue;
            }

            $path = $clip['scene_mp4_path'] ?? null;
            $pathCell = is_string($path) && $path !== ''
                ? OutputFormatter::escape($path)
                : '—';

            $skip = $clip['skip_reason'] ?? null;
            $outcome = $clip['outcome'] ?? '';

            if (is_string($skip) && $skip !== '') {
                $status = sprintf('<comment>skipped</> — %s', OutputFormatter::escape(self::humanizeKey($skip)));
            } elseif ($pathCell !== '—') {
                $status = '<info>ok</>';
            } else {
                $status = sprintf('<comment>skipped</> — %s', OutputFormatter::escape(self::humanizeOutcome($outcome)));
            }

            $audioCell = self::formatAudioCell($clip);

            $rows[] = [
                OutputFormatter::escape($label),
                $pathCell,
                $status,
                $audioCell,
            ];
        }

        $io->table(['Scene', 'scene.mp4', 'Status', 'Audio'], $rows);
    }

    /**
     * @param array<string, mixed> $clip
     */
    private static function formatAudioCell(array $clip): string
    {
        $mode = $clip['audio_mode'] ?? null;
        if (!is_string($mode) || $mode === '') {
            return '—';
        }

        return match ($mode) {
            'voice_muxed' => '<info>voice muxed</>',
            'silent_video_only' => '<comment>silent</> (no voice asset / not used)',
            'silent_fallback_after_voice_mux_failed' => '<comment>silent fallback</> (voice mux failed)',
            default => OutputFormatter::escape($mode),
        };
    }

    private static function writeScenarioAndManifest(SymfonyStyle $io, VideoGenerationResult $result): void
    {
        $io->writeln('');
        $io->section('Scenario video (scenario.mp4)');

        if ($result->scenarioOutputPath !== null) {
            $io->writeln(sprintf('<info>Wrote</> %s', OutputFormatter::escape($result->scenarioOutputPath)));
        } elseif ($result->scenarioSkipReason !== null) {
            $io->warning(OutputFormatter::escape($result->scenarioSkipReason));
        } else {
            $io->writeln('<comment>No scenario output path recorded.</>');
        }

        $rendering = $result->project->rendering();
        $included = $rendering['scenes_included_in_scenario'] ?? [];
        $excluded = $rendering['scenes_excluded_from_scenario'] ?? [];

        if (is_array($included) && $included !== []) {
            $io->writeln(sprintf(
                'Scenes in scenario: <info>%s</>',
                OutputFormatter::escape(self::formatSceneRefList($included))
            ));
        }

        if (is_array($excluded) && $excluded !== []) {
            $parts = [];
            foreach ($excluded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $num = $item['scene_number'] ?? '?';
                $reason = isset($item['reason']) && is_string($item['reason'])
                    ? self::humanizeKey($item['reason'])
                    : 'unknown';
                $parts[] = sprintf('#%s (%s)', $num, $reason);
            }
            if ($parts !== []) {
                $io->writeln(sprintf(
                    'Excluded from scenario: <comment>%s</>',
                    OutputFormatter::escape(implode('; ', $parts))
                ));
            }
        }

        if ($result->renderOutputPath !== null) {
            $io->writeln(sprintf('Manifest render: %s', OutputFormatter::escape($result->renderOutputPath)));
        }
    }

    /**
     * @param list<mixed> $items
     */
    private static function formatSceneRefList(array $items): string
    {
        $nums = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['scene_number'])) {
                $nums[] = (string) $item['scene_number'];
            }
        }

        return $nums !== [] ? implode(', ', $nums) : '—';
    }

    private static function humanizeKey(string $key): string
    {
        return str_replace('_', ' ', $key);
    }

    private static function humanizeOutcome(mixed $outcome): string
    {
        if (!is_string($outcome) || $outcome === '') {
            return 'unknown';
        }

        return self::humanizeKey(preg_replace('/^skipped_/', '', $outcome) ?? $outcome);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Video\Persistence;

use App\Domain\Video\Enum\ProjectStatus;
use App\Domain\Video\Enum\SceneStatus;
use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;
use App\Infrastructure\Video\Persistence\JsonVideoProjectMapper;
use PHPUnit\Framework\TestCase;

final class JsonVideoProjectMapperRenderingPersistenceTest extends TestCase
{
    public function test_clip_render_and_project_rendering_round_trip(): void
    {
        $created = new \DateTimeImmutable('2025-01-01T12:00:00+00:00');
        $updated = new \DateTimeImmutable('2025-01-01T12:30:00+00:00');

        $scene = new Scene(
            id: 'intro',
            number: 1,
            title: 'Intro',
            status: SceneStatus::Completed,
        );
        $scene->setClipRender([
            'scene_id' => 'intro',
            'scene_number' => 1,
            'outcome' => 'rendered_video_only',
            'details' => [],
            'scene_mp4_path' => '/var/videos/p/scenes/1-intro/scene.mp4',
            'skip_reason' => null,
            'used_voice' => false,
            'audio_mode' => 'silent_video_only',
        ]);

        $project = new VideoProject(
            id: 'video-x',
            sourceScenarioPath: '/path/def.yaml',
            title: 'T',
            status: ProjectStatus::Completed,
            createdAt: $created,
            updatedAt: $updated,
            rendering: [
                'scenario_mp4_path' => '/var/videos/p/render/scenario.mp4',
                'scenario_skip_reason' => null,
                'scenes_included_in_scenario' => [
                    ['scene_number' => 1, 'scene_id' => 'intro'],
                ],
                'scenes_excluded_from_scenario' => [
                    ['scene_number' => 2, 'scene_id' => 'outro', 'reason' => 'scene_mp4_missing'],
                ],
            ],
        );
        $project->addScene($scene);

        $mapper = new JsonVideoProjectMapper();
        $json = $mapper->toArray($project);
        $back = $mapper->fromArray($json);

        self::assertSame($project->rendering(), $back->rendering());
        $s = $back->scenes()[0];
        self::assertSame($scene->clipRender(), $s->clipRender());
    }
}

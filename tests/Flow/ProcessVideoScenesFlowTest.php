<?php

declare(strict_types=1);

namespace App\Tests\Flow;

use App\Application\Video\Port\VideoProjectRepositoryInterface;
use App\Domain\Video\Enum\SceneStatus;
use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;
use App\Flow\Model\VideoGenerationPayload;
use App\Flow\ProcessVideoScenesFlow;
use App\Flow\VideoSceneStep;
use App\Infrastructure\Video\Persistence\JsonVideoProjectMapper;
use App\Infrastructure\Video\Rendering\SceneClipRenderReport;
use Flow\Driver\FiberDriver;
use Flow\Ip;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit forces VIDEO_PARALLEL_FORK=0 (fork mocks break across processes); fork merge ordering is covered via
 * {@see ProcessVideoScenesFlow::sortForkSceneResultsBySceneIndex}.
 */
final class ProcessVideoScenesFlowTest extends TestCase
{
    public function test_failed_scene_does_not_stop_other_scenes_from_processing(): void
    {
        $sceneStep = $this->createMock(VideoSceneStep::class);
        $sceneStep->expects(self::exactly(3))
            ->method('processForGeneration')
            ->willReturnCallback(function (VideoGenerationPayload $payload, int $index): void {
                $scene = $payload->project->scenes()[$index];
                if ($index === 0) {
                    $scene->fail('first scene failed');
                    $payload->sceneClipReports[] = new SceneClipRenderReport(
                        $scene->id(),
                        $scene->number(),
                        SceneClipRenderReport::OUTCOME_SKIPPED_NOT_COMPLETED,
                    );
                } else {
                    $scene->complete();
                    $payload->sceneClipReports[] = new SceneClipRenderReport(
                        $scene->id(),
                        $scene->number(),
                        SceneClipRenderReport::OUTCOME_RENDERED_VIDEO_ONLY,
                    );
                }
                if ($scene->status() === SceneStatus::Failed) {
                    $payload->anyFailed = true;
                }
            });

        $repo = $this->createMock(VideoProjectRepositoryInterface::class);

        $flow = new ProcessVideoScenesFlow(
            $sceneStep,
            new JsonVideoProjectMapper(),
            $repo,
            4,
            new FiberDriver(),
        );

        $project = new VideoProject('p1', '/scenario.yaml', 'T');
        $project->addScene(new Scene(id: 's1', number: 1, title: 'One'));
        $project->addScene(new Scene(id: 's2', number: 2, title: 'Two'));
        $project->addScene(new Scene(id: 's3', number: 3, title: 'Three'));

        $payload = new VideoGenerationPayload('/scenario.yaml', null, null, $project, 'p1');
        $ip = new Ip($payload);
        $flow($ip);
        $flow->await();

        self::assertTrue($payload->anyFailed);
        self::assertSame(SceneStatus::Failed, $payload->project->scenes()[0]->status());
        self::assertSame(SceneStatus::Completed, $payload->project->scenes()[1]->status());
        self::assertSame(SceneStatus::Completed, $payload->project->scenes()[2]->status());
        self::assertCount(3, $payload->sceneClipReports);
    }

    public function test_sort_fork_scene_results_orders_by_scene_index(): void
    {
        $sorted = ProcessVideoScenesFlow::sortForkSceneResultsBySceneIndex([
            ['sceneIndex' => 2, 'sceneData' => [], 'clipReport' => [], 'anyFailed' => false],
            ['sceneIndex' => 0, 'sceneData' => [], 'clipReport' => [], 'anyFailed' => false],
            ['sceneIndex' => 1, 'sceneData' => [], 'clipReport' => [], 'anyFailed' => false],
        ]);

        self::assertSame([0, 1, 2], array_map(static fn (array $r): int => $r['sceneIndex'], $sorted));
    }
}

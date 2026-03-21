<?php

declare(strict_types=1);

namespace App\Tests\Flow;

use App\Application\Trailer\Port\TrailerProjectRepositoryInterface;
use App\Domain\Trailer\Enum\SceneStatus;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;
use App\Flow\Model\TrailerGenerationPayload;
use App\Flow\ProcessTrailerScenesFlow;
use App\Flow\TrailerSceneStep;
use App\Infrastructure\Trailer\Persistence\JsonTrailerProjectMapper;
use App\Infrastructure\Trailer\Rendering\SceneClipRenderReport;
use Flow\Driver\FiberDriver;
use Flow\Ip;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit forces TRAILER_PARALLEL_FORK=0 (fork mocks break across processes); fork merge ordering is covered via
 * {@see ProcessTrailerScenesFlow::sortForkSceneResultsBySceneIndex}.
 */
final class ProcessTrailerScenesFlowTest extends TestCase
{
    public function test_failed_scene_does_not_stop_other_scenes_from_processing(): void
    {
        $sceneStep = $this->createMock(TrailerSceneStep::class);
        $sceneStep->expects(self::exactly(3))
            ->method('processForGeneration')
            ->willReturnCallback(function (TrailerGenerationPayload $payload, int $index): void {
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

        $repo = $this->createMock(TrailerProjectRepositoryInterface::class);

        $flow = new ProcessTrailerScenesFlow(
            $sceneStep,
            new JsonTrailerProjectMapper(),
            $repo,
            4,
            new FiberDriver(),
        );

        $project = new TrailerProject('p1', '/scenario.yaml', 'T');
        $project->addScene(new Scene(id: 's1', number: 1, title: 'One'));
        $project->addScene(new Scene(id: 's2', number: 2, title: 'Two'));
        $project->addScene(new Scene(id: 's3', number: 3, title: 'Three'));

        $payload = new TrailerGenerationPayload('/scenario.yaml', null, null, $project, 'p1');
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
        $sorted = ProcessTrailerScenesFlow::sortForkSceneResultsBySceneIndex([
            ['sceneIndex' => 2, 'sceneData' => [], 'clipReport' => [], 'anyFailed' => false],
            ['sceneIndex' => 0, 'sceneData' => [], 'clipReport' => [], 'anyFailed' => false],
            ['sceneIndex' => 1, 'sceneData' => [], 'clipReport' => [], 'anyFailed' => false],
        ]);

        self::assertSame([0, 1, 2], array_map(static fn (array $r): int => $r['sceneIndex'], $sorted));
    }
}

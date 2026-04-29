<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Video\Persistence;

use App\Domain\Video\Asset;
use App\Domain\Video\Enum\AssetStatus;
use App\Domain\Video\Enum\AssetType;
use App\Domain\Video\Enum\ProjectStatus;
use App\Domain\Video\Enum\SceneStatus;
use App\Domain\Video\Scene;
use App\Domain\Video\VideoProject;
use App\Infrastructure\Video\Persistence\JsonVideoProjectMapper;
use PHPUnit\Framework\TestCase;

final class JsonVideoProjectMapperAssetMetadataTest extends TestCase
{
    public function test_asset_metadata_round_trips_including_failure_fields(): void
    {
        $created = new \DateTimeImmutable('2025-01-01T12:00:00+00:00');
        $updated = new \DateTimeImmutable('2025-01-01T12:30:00+00:00');

        $project = new VideoProject(
            id: 'video-abcd',
            sourceScenarioPath: '/path/def.yaml',
            title: 'Test',
            status: ProjectStatus::Processing,
            createdAt: $created,
            updatedAt: $updated,
        );

        $scene = new Scene(
            id: 's1',
            number: 1,
            title: 'One',
            status: SceneStatus::Failed,
            lastError: 'Video generation failed: …',
        );

        $videoMeta = [
            'provider' => 'replicate-video',
            'prediction_id' => 'pred-999',
            'remote_job_id' => 'pred-999',
            'provider_model' => 'minimax/hailuo-02-fast',
            'remote_output_url' => 'https://replicate.delivery/out.mp4',
            'local_path' => '/var/videos/x/scenes/1-s1/video.mp4',
            'local_artifact_path' => '/var/videos/x/scenes/1-s1/video.mp4',
            'provider_state' => 'error',
            'provider_error_message' => 'Replicate prediction pred-999 failed with status "failed": oops',
            'failure_at' => '2025-01-01T12:29:00+00:00',
            'remote_status' => 'failed',
            'remote_error_detail' => 'oops',
            'replicate_preset' => 'hailuo',
        ];

        $video = new Asset(
            id: 's1-video',
            sceneId: 's1',
            type: AssetType::Video,
            status: AssetStatus::Failed,
            provider: 'replicate-video',
            path: '/var/videos/x/scenes/1-s1/video.mp4',
            metadata: $videoMeta,
            lastError: 'Video generation failed: …',
        );
        $scene->addAsset($video);
        $project->addScene($scene);

        $mapper = new JsonVideoProjectMapper();
        $json = $mapper->toArray($project);
        $back = $mapper->fromArray($json);

        $roundTripVideo = $back->scenes()[0]->assets()[0];
        self::assertSame(AssetStatus::Failed, $roundTripVideo->status());
        self::assertSame('replicate-video', $roundTripVideo->provider());
        self::assertSame($videoMeta, $roundTripVideo->metadata());
        self::assertSame('Video generation failed: …', $roundTripVideo->lastError());
    }
}

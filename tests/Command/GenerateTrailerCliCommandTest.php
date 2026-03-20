<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Application\Trailer\DTO\TrailerGenerationResult;
use App\Application\Trailer\Port\TrailerGenerationOrchestratorInterface;
use App\Command\GenerateTrailerCliCommand;
use App\Domain\Trailer\Asset;
use App\Domain\Trailer\Enum\AssetStatus;
use App\Domain\Trailer\Enum\AssetType;
use App\Domain\Trailer\Scene;
use App\Domain\Trailer\TrailerProject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class GenerateTrailerCliCommandTest extends TestCase
{
    public function test_scene1_routing_shows_voice_fallback_and_real_error(): void
    {
        $yaml = tempnam(sys_get_temp_dir(), 'dw-yml-');
        self::assertNotFalse($yaml);
        file_put_contents($yaml, 'placeholder: true');

        $voice = new Asset('s1-voice', 's1', AssetType::Voice, AssetStatus::Pending, null, null);
        $voice->complete('/tmp/v.mp3', [
            'provider' => 'fake-voice',
            'fallback_from' => 'real',
            'real_attempt_error_message' => 'CLI synthetic Replicate voice error',
        ]);
        $video = new Asset('s1-video', 's1', AssetType::Video, AssetStatus::Pending, null, null);
        $video->complete('/tmp/x.mp4', ['provider' => 'replicate-video']);

        $scene = new Scene('s1', 1, 'One');
        $scene->addAsset($voice);
        $scene->addAsset($video);
        $scene->complete();

        $project = new TrailerProject('p-fallback-cli', $yaml, 'T');
        $project->addScene($scene);
        $project->complete();

        $orchestrator = $this->createMock(TrailerGenerationOrchestratorInterface::class);
        $orchestrator->expects(self::once())
            ->method('generateFromYaml')
            ->with($yaml, null)
            ->willReturn(new TrailerGenerationResult($project, null, null));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/tmp/dw-proj-root');

        $command = new GenerateTrailerCliCommand($orchestrator, $kernel);
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        try {
            $tester->execute(['command' => $command->getName(), 'yaml' => $yaml], ['decorated' => false]);
        } finally {
            @unlink($yaml);
        }

        $out = $tester->getDisplay();
        self::assertStringContainsString('Render — scene clips', $out);
        self::assertStringContainsString('Scenario video (scenario.mp4)', $out);
        self::assertStringContainsString('Scene 1 — provider routing', $out);
        self::assertStringContainsString('Fake fallback used', $out);
        self::assertStringContainsString('Real attempt error', $out);
        self::assertStringContainsString('CLI synthetic Replicate voice error', $out);
        self::assertStringContainsString('replicate-video', $out);
    }

    public function test_scene1_routing_shows_real_success_without_fallback(): void
    {
        $yaml = tempnam(sys_get_temp_dir(), 'dw-yml-');
        self::assertNotFalse($yaml);
        file_put_contents($yaml, 'placeholder: true');

        $voice = new Asset('s1-voice', 's1', AssetType::Voice, AssetStatus::Pending, null, null);
        $voice->complete('/tmp/v.mp3', ['provider' => 'replicate-voice']);
        $video = new Asset('s1-video', 's1', AssetType::Video, AssetStatus::Pending, null, null);
        $video->complete('/tmp/x.mp4', ['provider' => 'replicate-video']);

        $scene = new Scene('s1', 1, 'One');
        $scene->addAsset($voice);
        $scene->addAsset($video);
        $scene->complete();

        $project = new TrailerProject('p-real-cli', $yaml, 'T');
        $project->addScene($scene);
        $project->complete();

        $orchestrator = $this->createMock(TrailerGenerationOrchestratorInterface::class);
        $orchestrator->method('generateFromYaml')->willReturn(new TrailerGenerationResult($project, null, null));

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/tmp/dw-proj-root');

        $command = new GenerateTrailerCliCommand($orchestrator, $kernel);
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        try {
            $tester->execute(['command' => $command->getName(), 'yaml' => $yaml], ['decorated' => false]);
        } finally {
            @unlink($yaml);
        }

        $out = $tester->getDisplay();
        self::assertStringContainsString('Render — scene clips', $out);
        self::assertStringContainsString('Scenario video (scenario.mp4)', $out);
        self::assertStringContainsString('Scene 1 — provider routing', $out);
        self::assertStringContainsString('Real provider attempted', $out);
        self::assertStringContainsString('succeeded', $out);
        self::assertSame(2, substr_count($out, 'Fake fallback used: no'));
        self::assertStringNotContainsString('Fake fallback used: yes', $out);
        self::assertStringNotContainsString('Real attempt error', $out);
    }
}

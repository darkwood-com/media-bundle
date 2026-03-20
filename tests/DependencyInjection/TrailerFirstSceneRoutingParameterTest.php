<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ensures trailer.real_for_first_scene_only is wired identically for video and voice scene-aware providers.
 */
final class TrailerFirstSceneRoutingParameterTest extends KernelTestCase
{
    public function test_video_and_voice_first_scene_flags_share_the_same_resolved_parameter(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $video = $container->getParameter('trailer.video.real_for_first_scene_only');
        $voice = $container->getParameter('trailer.voice.real_for_first_scene_only');
        $shared = $container->getParameter('trailer.real_for_first_scene_only');

        self::assertIsBool($video);
        self::assertIsBool($voice);
        self::assertIsBool($shared);
        self::assertSame($shared, $video);
        self::assertSame($shared, $voice);
    }
}

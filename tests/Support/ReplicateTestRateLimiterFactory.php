<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Trailer\Provider\Replicate\ReplicateSlidingWindowRateLimiter;

/**
 * High-limit rate limiter for unit tests (avoids blocking; unique state file per instance).
 */
final class ReplicateTestRateLimiterFactory
{
    public static function create(): ReplicateSlidingWindowRateLimiter
    {
        return new ReplicateSlidingWindowRateLimiter(
            sys_get_temp_dir() . '/dw-replicate-rl-test-' . uniqid('', true) . '.json',
            999_999,
            9_999_999,
        );
    }
}

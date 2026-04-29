<?php

declare(strict_types=1);

namespace App\Application\Video\Exception;

class InvalidVideoDefinitionException extends \InvalidArgumentException
{
    public static function missingKey(string $key, ?string $context = null): self
    {
        $message = $context
            ? sprintf('Video definition is invalid: missing required key "%s" (%s).', $key, $context)
            : sprintf('Video definition is invalid: missing required key "%s".', $key);

        return new self($message);
    }

    public static function invalidType(string $key, string $expected, string $context = ''): self
    {
        $message = $context
            ? sprintf('Video definition is invalid: "%s" must be %s (%s).', $key, $expected, $context)
            : sprintf('Video definition is invalid: "%s" must be %s.', $key, $expected);

        return new self($message);
    }

    public static function invalidScene(int $index, string $reason): self
    {
        return new self(sprintf(
            'Video definition is invalid: scene at index %d: %s',
            $index,
            $reason
        ));
    }

    public static function parseError(string $path, string $message): self
    {
        return new self(sprintf(
            'Failed to load video definition from "%s": %s',
            $path,
            $message
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Trailer\Exception;

class InvalidTrailerDefinitionException extends \InvalidArgumentException
{
    public static function missingKey(string $key, ?string $context = null): self
    {
        $message = $context
            ? sprintf('Trailer definition is invalid: missing required key "%s" (%s).', $key, $context)
            : sprintf('Trailer definition is invalid: missing required key "%s".', $key);

        return new self($message);
    }

    public static function invalidType(string $key, string $expected, string $context = ''): self
    {
        $message = $context
            ? sprintf('Trailer definition is invalid: "%s" must be %s (%s).', $key, $expected, $context)
            : sprintf('Trailer definition is invalid: "%s" must be %s.', $key, $expected);

        return new self($message);
    }

    public static function invalidScene(int $index, string $reason): self
    {
        return new self(sprintf(
            'Trailer definition is invalid: scene at index %d: %s',
            $index,
            $reason
        ));
    }

    public static function parseError(string $path, string $message): self
    {
        return new self(sprintf(
            'Failed to load trailer definition from "%s": %s',
            $path,
            $message
        ));
    }
}

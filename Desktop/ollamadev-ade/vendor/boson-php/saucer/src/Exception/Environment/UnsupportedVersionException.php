<?php

declare(strict_types=1);

namespace Boson\Component\Saucer\Exception\Environment;

class UnsupportedVersionException extends EnvironmentException
{
    public static function becauseVersionIsInvalid(
        string $version,
        string $min,
        string $max,
        ?\Throwable $previous = null,
    ): self {
        $message = \vsprintf('The current version of the library is %s, '
            . 'but at least %s (but not higher than %s) is supported', [
            $version,
            $min,
            $max,
        ]);

        return new self($message, 0, $previous);
    }
}

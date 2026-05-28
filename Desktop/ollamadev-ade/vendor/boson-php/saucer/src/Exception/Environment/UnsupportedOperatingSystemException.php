<?php

declare(strict_types=1);

namespace Boson\Component\Saucer\Exception\Environment;

class UnsupportedOperatingSystemException extends EnvironmentException
{
    public static function becauseOperatingSystemIsInvalid(string $os, ?\Throwable $previous = null): self
    {
        $message = \sprintf('Unsupported "%s" operating system', $os);

        return new self($message, 0, $previous);
    }
}

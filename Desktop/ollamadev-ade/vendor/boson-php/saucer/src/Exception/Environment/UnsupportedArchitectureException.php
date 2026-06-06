<?php

declare(strict_types=1);

namespace Boson\Component\Saucer\Exception\Environment;

class UnsupportedArchitectureException extends EnvironmentException
{
    public static function becauseArchitectureIsInvalid(string $architecture, ?\Throwable $previous = null): self
    {
        $message = \sprintf('Unsupported CPU architecture %s', $architecture);

        return new self($message, 0, $previous);
    }
}

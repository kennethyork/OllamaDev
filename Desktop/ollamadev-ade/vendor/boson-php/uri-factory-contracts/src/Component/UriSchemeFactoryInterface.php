<?php

declare(strict_types=1);

namespace Boson\Contracts\Uri\Factory\Component;

use Boson\Contracts\Uri\Component\SchemeInterface;
use Boson\Contracts\Uri\Factory\Exception\InvalidUriComponentExceptionInterface;

interface UriSchemeFactoryInterface
{
    /**
     * Returns {@see SchemeInterface} instance from passed
     * non-empty {@see string} or {@see \Stringable} argument.
     *
     * @param \Stringable|non-empty-string $scheme
     *
     * @throws InvalidUriComponentExceptionInterface in case of invalid scheme
     *         argument is passed
     */
    public function createSchemeFromString(\Stringable|string $scheme): SchemeInterface;
}

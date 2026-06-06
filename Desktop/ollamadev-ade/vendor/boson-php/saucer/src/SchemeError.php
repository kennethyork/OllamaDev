<?php

declare(strict_types=1);

namespace Boson\Component\Saucer;

final readonly class SchemeError
{
    public const int SAUCER_REQUEST_ERROR_NOT_FOUND = 0;
    public const int SAUCER_REQUEST_ERROR_INVALID = 1;
    public const int SAUCER_REQUEST_ERROR_ABORTED = 2;
    public const int SAUCER_REQUEST_ERROR_DENIED = 3;
    public const int SAUCER_REQUEST_ERROR_FAILED = 4;

    private function __construct() {}
}

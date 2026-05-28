<?php

declare(strict_types=1);

namespace Boson\Component\Saucer;

final readonly class LoadTime
{
    public const int SAUCER_LOAD_TIME_CREATION = 0;
    public const int SAUCER_LOAD_TIME_READY = 1;

    private function __construct() {}
}

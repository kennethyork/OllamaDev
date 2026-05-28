<?php

declare(strict_types=1);

namespace Boson\Component\Saucer;

final readonly class State
{
    public const int SAUCER_STATE_STARTED = 0;
    public const int SAUCER_STATE_FINISHED = 1;

    private function __construct() {}
}

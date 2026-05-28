<?php

declare(strict_types=1);

namespace Boson\Component\Saucer;

final readonly class WindowEdge
{
    public const int SAUCER_WINDOW_EDGE_TOP = 1 << 0;
    public const int SAUCER_WINDOW_EDGE_BOTTOM = 1 << 1;
    public const int SAUCER_WINDOW_EDGE_LEFT = 1 << 2;
    public const int SAUCER_WINDOW_EDGE_RIGHT = 1 << 3;

    private function __construct() {}
}

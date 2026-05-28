<?php

declare(strict_types=1);

namespace Boson\Component\Saucer;

final readonly class Policy
{
    public const int SAUCER_POLICY_ALLOW = 0;
    public const int SAUCER_POLICY_BLOCK = 1;

    private function __construct() {}
}

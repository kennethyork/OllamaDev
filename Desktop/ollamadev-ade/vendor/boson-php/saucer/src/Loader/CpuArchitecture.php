<?php

declare(strict_types=1);

namespace Boson\Component\Saucer\Loader;

enum CpuArchitecture
{
    case x86;
    case Amd64;
    case Arm;
    case Arm64;
    case Other;

    public static function createFromGlobals(): self
    {
        return match (\strtolower(\php_uname('m'))) {
            'x86', 'i386', 'ia32' => self::x86,
            'amd64', 'x64', 'x86_64' => self::Amd64,
            'arm', 'armel', 'armhf' => self::Arm,
            'arm64', 'aarch64', 'arm64ilp32' => self::Arm64,
            default => self::Other,
        };
    }
}

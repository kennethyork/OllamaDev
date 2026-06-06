<?php

declare(strict_types=1);

namespace Boson\Component\Saucer\Loader;

enum OperatingSystem
{
    case Windows;
    case Linux;
    case BSD;
    case MacOS;
    case Other;

    public static function createFromGlobals(): self
    {
        return match (\PHP_OS_FAMILY) {
            'Windows' => self::Windows,
            'Linux' => self::Linux,
            'BSD' => self::BSD,
            'Darwin' => self::MacOS,
            default => self::Other,
        };
    }
}

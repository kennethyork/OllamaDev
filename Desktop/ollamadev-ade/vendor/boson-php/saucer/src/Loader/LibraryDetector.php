<?php

declare(strict_types=1);

namespace Boson\Component\Saucer\Loader;

use Boson\Component\Saucer\Exception\Environment\UnsupportedArchitectureException;
use Boson\Component\Saucer\Exception\Environment\UnsupportedOperatingSystemException;

final class LibraryDetector implements \Stringable
{
    private const string DEFAULT_BIN_DIR = __DIR__ . '/../../bin';
    private const ?string DEFAULT_PHAR_DIR = null;

    private const ?string DEFAULT_LIB_LINUX_X86 = 'libboson-linux-x86_64.so';
    private const ?string DEFAULT_LIB_LINUX_AMD64 = 'libboson-linux-x86_64.so';
    private const ?string DEFAULT_LIB_LINUX_ARM64 = 'libboson-linux-aarch64.so';
    private const ?string DEFAULT_LIB_LINUX_ARM = null;
    private const ?string DEFAULT_LIB_WINDOWS_X86 = 'libboson-windows-x86_64.dll';
    private const ?string DEFAULT_LIB_WINDOWS_AMD64 = 'libboson-windows-x86_64.dll';
    private const ?string DEFAULT_LIB_WINDOWS_ARM = null;
    private const ?string DEFAULT_LIB_WINDOWS_ARM64 = null;
    private const ?string DEFAULT_LIB_MAC_X86 = 'libboson-darwin-universal.dylib';
    private const ?string DEFAULT_LIB_MAC_AMD64 = 'libboson-darwin-universal.dylib';
    private const ?string DEFAULT_LIB_MAC_ARM = null;
    private const ?string DEFAULT_LIB_MAC_ARM64 = 'libboson-darwin-universal.dylib';

    /**
     * @var non-empty-string
     */
    public string $name {
        get {
            $os = $this->os ?? OperatingSystem::createFromGlobals();
            $arch = $this->arch ?? CpuArchitecture::createFromGlobals();

            return match ($os) {
                OperatingSystem::Windows => match ($arch) {
                    CpuArchitecture::x86 => self::DEFAULT_LIB_WINDOWS_X86,
                    CpuArchitecture::Amd64 => self::DEFAULT_LIB_WINDOWS_AMD64,
                    CpuArchitecture::Arm => self::DEFAULT_LIB_WINDOWS_ARM,
                    CpuArchitecture::Arm64 => self::DEFAULT_LIB_WINDOWS_ARM64,
                    default => throw UnsupportedArchitectureException::becauseArchitectureIsInvalid(
                        architecture: \php_uname('m'),
                    ),
                },
                OperatingSystem::Linux,
                OperatingSystem::BSD => match ($arch) {
                    CpuArchitecture::x86 => self::DEFAULT_LIB_LINUX_X86,
                    CpuArchitecture::Amd64 => self::DEFAULT_LIB_LINUX_AMD64,
                    CpuArchitecture::Arm => self::DEFAULT_LIB_LINUX_ARM,
                    CpuArchitecture::Arm64 => self::DEFAULT_LIB_LINUX_ARM64,
                    default => throw UnsupportedArchitectureException::becauseArchitectureIsInvalid(
                        architecture: \php_uname('m'),
                    ),
                },
                OperatingSystem::MacOS => match ($arch) {
                    CpuArchitecture::x86 => self::DEFAULT_LIB_MAC_X86,
                    CpuArchitecture::Amd64 => self::DEFAULT_LIB_MAC_AMD64,
                    CpuArchitecture::Arm => self::DEFAULT_LIB_MAC_ARM,
                    CpuArchitecture::Arm64 => self::DEFAULT_LIB_MAC_ARM64,
                    default => throw UnsupportedArchitectureException::becauseArchitectureIsInvalid(
                        architecture: \php_uname('m'),
                    ),
                },
                default => null,
            } ?? throw UnsupportedOperatingSystemException::becauseOperatingSystemIsInvalid(
                os: \PHP_OS_FAMILY,
            );
        }
    }

    /**
     * @var non-empty-string
     */
    public string $directory {
        get {
            if (!\extension_loaded('phar') || \Phar::running() === '') {
                return $this->localDirectory;
            }

            $directory = \dirname(\Phar::running(false));

            if ($this->pharDirectory !== null) {
                return $directory . '/' . $this->pharDirectory;
            }

            /** @var non-empty-string */
            return $directory;
        }
    }

    /**
     * @phpstan-pure
     */
    public function __construct(
        private readonly ?OperatingSystem $os = null,
        private readonly ?CpuArchitecture $arch = null,
        /**
         * @var non-empty-string
         */
        private readonly string $localDirectory = self::DEFAULT_BIN_DIR,
        /**
         * @var non-empty-string|null
         */
        private readonly ?string $pharDirectory = self::DEFAULT_PHAR_DIR,
    ) {}

    /**
     * @api
     * @phpstan-pure
     */
    public function withOperatingSystem(?OperatingSystem $os): self
    {
        return new self(
            os: $os,
            arch: $this->arch,
            localDirectory: $this->localDirectory,
            pharDirectory: $this->pharDirectory,
        );
    }

    /**
     * @api
     * @phpstan-pure
     */
    public function withCpuArchitecture(?CpuArchitecture $arch): self
    {
        return new self(
            os: $this->os,
            arch: $arch,
            localDirectory: $this->localDirectory,
            pharDirectory: $this->pharDirectory,
        );
    }

    /**
     * @api
     * @phpstan-pure
     *
     * @param non-empty-string $directory
     */
    public function withLocalDirectory(string $directory): self
    {
        return new self(
            os: $this->os,
            arch: $this->arch,
            localDirectory: $directory,
            pharDirectory: $this->pharDirectory,
        );
    }

    /**
     * @api
     * @phpstan-pure
     *
     * @param non-empty-string $directory
     */
    public function withPharDirectory(string $directory): self
    {
        return new self(
            os: $this->os,
            arch: $this->arch,
            localDirectory: $this->localDirectory,
            pharDirectory: $directory,
        );
    }

    /**
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->directory . '/' . $this->name;
    }
}

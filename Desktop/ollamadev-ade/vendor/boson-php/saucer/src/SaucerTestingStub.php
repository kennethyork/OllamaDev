<?php

declare(strict_types=1);

namespace Boson\Component\Saucer;

/**
 * An {@see SaucerInterface} implementation for testing purposes only
 */
class SaucerTestingStub implements SaucerInterface
{
    /**
     * @var array<non-empty-string, callable>
     */
    private array $methods = [];

    /**
     * @var array<non-empty-string, callable>
     */
    private array $implementations = [];

    /**
     * @param non-empty-string $method
     */
    public function onMethodCall(string $method, ?callable $callable = null): void
    {
        $this->methods[$method] = ($callable ?? function () {});
    }

    /**
     * @param non-empty-string $method
     */
    public function addDefaultMethod(string $method, ?callable $callable = null): void
    {
        $this->implementations[$method] = ($callable ?? function () {});
    }

    public function __call(string $method, array $args): mixed
    {
        $callback = $this->methods[$method] ?? $this->implementations[$method] ?? null;

        if ($callback === null) {
            throw new \BadMethodCallException(\vsprintf('Call to non-handled method %s() of testing proxy', [
                $method,
            ]));
        }

        try {
            return $callback(...$args);
        } finally {
            unset($this->methods[$method]);
        }
    }
}

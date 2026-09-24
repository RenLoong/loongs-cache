<?php

declare(strict_types=1);

namespace Loongs\Cache\Support;

use Loongs\Cache\Contract\RedisRunnerInterface;

/**
 * Wraps a callable(fn(\Redis): mixed): mixed as a RedisRunnerInterface.
 * Typical framework wiring:
 *   new CallableRedisRunner(fn ($cb) => $redisManager->run($cb, 'default'))
 */
final class CallableRedisRunner implements RedisRunnerInterface
{
    /** @param callable(callable(\Redis): mixed): mixed $runner */
    public function __construct(
        private readonly mixed $runner,
    ) {
        if (!is_callable($this->runner)) {
            throw new \InvalidArgumentException('Redis runner must be callable.');
        }
    }

    public function run(callable $callback): mixed
    {
        return ($this->runner)($callback);
    }
}

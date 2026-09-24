<?php

declare(strict_types=1);

namespace Loongs\Cache\Contract;

/**
 * Abstraction over a pooled Redis client so loongs/cache does not hard-require
 * Loongs\Redis\RedisManager. Any object/callable that borrows a \Redis, runs
 * a callback, and returns the connection works.
 */
interface RedisRunnerInterface
{
    /**
     * @template T
     * @param callable(\Redis): T $callback
     * @return T
     */
    public function run(callable $callback): mixed;
}

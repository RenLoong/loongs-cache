<?php

declare(strict_types=1);

namespace Loongs\Cache\Support;

use Loongs\Cache\Contract\RedisRunnerInterface;

/**
 * Duck-typed adapter for Loongs\Redis\RedisManager (or any object with run()).
 * Avoids a hard composer dependency on loongs/framework.
 */
final class RedisManagerRunner implements RedisRunnerInterface
{
    public function __construct(
        private readonly object $manager,
        private readonly ?string $connection = null,
    ) {
        if (!method_exists($this->manager, 'run')) {
            throw new \InvalidArgumentException(
                'Redis manager must expose run(callable, ?string): mixed.'
            );
        }
    }

    public function run(callable $callback): mixed
    {
        return $this->manager->run($callback, $this->connection);
    }
}

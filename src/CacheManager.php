<?php

declare(strict_types=1);

namespace Loongs\Cache;

use Loongs\Cache\Contract\RedisRunnerInterface;
use Loongs\Cache\Exception\CacheException;
use Loongs\Cache\Exception\InvalidArgumentException;
use Loongs\Cache\Store\FileStore;
use Loongs\Cache\Store\MemoryStore;
use Loongs\Cache\Store\RedisStore;
use Loongs\Cache\Store\TableStore;
use Loongs\Cache\Support\CallableRedisRunner;
use Loongs\Cache\Support\RedisManagerRunner;

/**
 * Resolves named cache stores from config.
 *
 * Config shape (server/config/cache.php):
 *   default, prefix, stores.{name}.driver + driver options
 */
final class CacheManager
{
    /** @var array<string, Repository> */
    private array $stores = [];

    private readonly string $default;

    private readonly string $prefix;

    /** @var array<string, mixed> */
    private readonly array $storeConfigs;

    private readonly ?string $basePath;

    private mixed $redisFactory;

    /**
     * @param array<string, mixed> $config
     * @param null|RedisRunnerInterface|object|callable $redis  RedisManager, runner, or callable
     */
    public function __construct(
        array $config,
        mixed $redis = null,
        ?string $basePath = null,
    ) {
        $default = $config['default'] ?? 'file';
        $this->default = is_string($default) && $default !== '' ? $default : 'file';

        $prefix = $config['prefix'] ?? 'loong:cache:';
        $this->prefix = is_string($prefix) ? $prefix : 'loong:cache:';

        $stores = $config['stores'] ?? [];
        $this->storeConfigs = is_array($stores) ? $stores : [];

        $this->basePath = $basePath !== null ? rtrim($basePath, '/\\') : null;
        $this->redisFactory = $redis;
    }

    public function store(?string $name = null): Repository
    {
        $name ??= $this->default;
        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        if (!isset($this->storeConfigs[$name]) || !is_array($this->storeConfigs[$name])) {
            throw new InvalidArgumentException("Cache store [{$name}] is not configured.");
        }

        $this->stores[$name] = $this->resolve($name, $this->storeConfigs[$name]);

        return $this->stores[$name];
    }

    public function getDefaultStore(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function storeNames(): array
    {
        return array_values(array_filter(array_keys($this->storeConfigs), 'is_string'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->store()->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->store()->delete($key);
    }

    public function has(string $key): bool
    {
        return $this->store()->has($key);
    }

    public function clear(): bool
    {
        return $this->store()->clear();
    }

    public function remember(string $key, null|int|\DateInterval $ttl, callable $callback): mixed
    {
        return $this->store()->remember($key, $ttl, $callback);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->store()->pull($key, $default);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->store()->forever($key, $value);
    }

    public function add(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        return $this->store()->add($key, $value, $ttl);
    }

    public function increment(string $key, int $value = 1): int|false
    {
        return $this->store()->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->store()->decrement($key, $value);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function resolve(string $name, array $cfg): Repository
    {
        $driver = $cfg['driver'] ?? $name;
        if (!is_string($driver)) {
            throw new InvalidArgumentException("Cache store [{$name}] has invalid driver.");
        }

        $prefix = isset($cfg['prefix']) && is_string($cfg['prefix']) ? $cfg['prefix'] : $this->prefix;

        $store = match ($driver) {
            'file' => $this->createFileStore($cfg, $prefix),
            'redis' => $this->createRedisStore($cfg, $prefix),
            'memory', 'array' => $this->createMemoryStore($cfg, $prefix),
            'table' => $this->createTableStore($cfg, $prefix),
            default => throw new InvalidArgumentException("Unsupported cache driver [{$driver}]."),
        };

        return new Repository($store);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function createFileStore(array $cfg, string $prefix): FileStore
    {
        $path = $cfg['path'] ?? null;
        if (!is_string($path) || $path === '') {
            $path = ($this->basePath ?? sys_get_temp_dir()) . '/runtime/cache';
        } elseif ($path[0] !== '/' && $this->basePath !== null) {
            $path = $this->basePath . '/' . ltrim($path, '/\\');
        }

        return new FileStore($path, $prefix);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function createRedisStore(array $cfg, string $prefix): RedisStore
    {
        $connection = $cfg['connection'] ?? null;
        $connection = is_string($connection) ? $connection : null;

        return new RedisStore($this->resolveRedisRunner($connection), $prefix);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function createMemoryStore(array $cfg, string $prefix): MemoryStore
    {
        $max = $cfg['max_items'] ?? null;
        $maxItems = is_int($max) || is_numeric($max) ? (int) $max : null;
        if ($maxItems !== null && $maxItems <= 0) {
            $maxItems = null;
        }

        return new MemoryStore($prefix, $maxItems);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function createTableStore(array $cfg, string $prefix): TableStore
    {
        if (!class_exists(TableStore::class) || !class_exists(\Swoole\Table::class)) {
            throw new CacheException('Swoole\\Table is required for the table cache driver.');
        }

        $size = isset($cfg['size']) ? (int) $cfg['size'] : 1024;
        $valueSize = isset($cfg['value_size']) ? (int) $cfg['value_size'] : 4096;

        return new TableStore($prefix, $size, $valueSize);
    }

    private function resolveRedisRunner(?string $connection): RedisRunnerInterface
    {
        $factory = $this->redisFactory;

        if ($factory instanceof RedisRunnerInterface) {
            return $factory;
        }

        if (is_callable($factory)) {
            // callable may already bind connection, or accept connection name as 2nd use
            return new CallableRedisRunner(static function (callable $cb) use ($factory, $connection): mixed {
                // Prefer factory(callback, connection) when possible
                try {
                    return $factory($cb, $connection);
                } catch (\ArgumentCountError) {
                    return $factory($cb);
                }
            });
        }

        if (is_object($factory) && method_exists($factory, 'run')) {
            return new RedisManagerRunner($factory, $connection);
        }

        throw new CacheException(
            'Redis cache store requires a RedisManager, RedisRunnerInterface, or callable runner.'
        );
    }
}

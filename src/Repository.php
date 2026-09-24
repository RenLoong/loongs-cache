<?php

declare(strict_types=1);

namespace Loongs\Cache;

use DateInterval;
use Loongs\Cache\Contract\CacheStoreInterface;
use Loongs\Cache\Support\KeyValidator;
use Loongs\Cache\Support\Ttl;
use Psr\SimpleCache\CacheInterface;

/**
 * High-level cache repository implementing PSR-16 plus Laravel-style helpers.
 */
final class Repository implements CacheInterface
{
    public function __construct(
        private readonly CacheStoreInterface $store,
    ) {
    }

    public function store(): CacheStoreInterface
    {
        return $this->store;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        KeyValidator::assert($key);
        if (!$this->store->has($key)) {
            return $default;
        }

        return $this->store->get($key);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assert($key);

        return $this->store->set($key, $value, Ttl::toSeconds($ttl));
    }

    public function delete(string $key): bool
    {
        KeyValidator::assert($key);

        return $this->store->delete($key);
    }

    public function clear(): bool
    {
        return $this->store->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $list = KeyValidator::assertList($keys);
        $out = [];
        foreach ($list as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $map = [];
        foreach ($values as $key => $value) {
            $k = is_string($key) ? $key : (string) $key;
            KeyValidator::assert($k);
            $map[$k] = $value;
        }

        return $this->store->setMultiple($map, Ttl::toSeconds($ttl));
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->store->deleteMultiple(KeyValidator::assertList($keys));
    }

    public function has(string $key): bool
    {
        KeyValidator::assert($key);

        return $this->store->has($key);
    }

    public function increment(string $key, int $value = 1): int|false
    {
        KeyValidator::assert($key);

        return $this->store->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        KeyValidator::assert($key);

        return $this->store->decrement($key, $value);
    }

    public function add(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assert($key);

        return $this->store->add($key, $value, Ttl::toSeconds($ttl));
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, null);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, null|int|DateInterval $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->delete($key);

        return $value;
    }
}

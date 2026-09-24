<?php

declare(strict_types=1);

namespace Loongs\Cache\Store;

use Loongs\Cache\Contract\CacheStoreInterface;

/**
 * In-process array cache (per worker / per process).
 * NOT shared across Swoole workers. Optional max_items with LRU eviction.
 */
final class MemoryStore implements CacheStoreInterface
{
    /** @var array<string, array{value: mixed, expiry: int}> expiry 0 = forever */
    private array $items = [];

    /** @var array<string, int> key => last access monotonic counter for LRU */
    private array $access = [];

    private int $clock = 0;

    public function __construct(
        private readonly string $prefix = '',
        private readonly ?int $maxItems = null,
    ) {
    }

    public function get(string $key): mixed
    {
        $full = $this->fullKey($key);
        if (!isset($this->items[$full])) {
            return null;
        }

        $item = $this->items[$full];
        if ($item['expiry'] !== 0 && $item['expiry'] <= time()) {
            unset($this->items[$full], $this->access[$full]);
            return null;
        }

        $this->touch($full);

        return $item['value'];
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $full = $this->fullKey($key);
        $expiry = $ttlSeconds === null ? 0 : time() + $ttlSeconds;
        $this->items[$full] = ['value' => $value, 'expiry' => $expiry];
        $this->touch($full);
        $this->evictIfNeeded();

        return true;
    }

    public function delete(string $key): bool
    {
        $full = $this->fullKey($key);
        unset($this->items[$full], $this->access[$full]);

        return true;
    }

    public function clear(): bool
    {
        if ($this->prefix === '') {
            $this->items = [];
            $this->access = [];
            return true;
        }

        foreach (array_keys($this->items) as $full) {
            if (str_starts_with($full, $this->prefix)) {
                unset($this->items[$full], $this->access[$full]);
            }
        }

        return true;
    }

    public function has(string $key): bool
    {
        $full = $this->fullKey($key);
        if (!isset($this->items[$full])) {
            return false;
        }
        $item = $this->items[$full];
        if ($item['expiry'] !== 0 && $item['expiry'] <= time()) {
            unset($this->items[$full], $this->access[$full]);
            return false;
        }

        return true;
    }

    public function getMultiple(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function setMultiple(array $values, ?int $ttlSeconds): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttlSeconds);
        }

        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $current = $this->get($key);
        if ($current === null && !$this->has($key)) {
            $current = 0;
        }
        if ($current === null) {
            $current = 0;
        }
        if (!is_int($current) && !is_float($current) && !(is_string($current) && is_numeric($current))) {
            return false;
        }

        $next = (int) $current + $value;
        $ttl = $this->remainingTtl($key);
        $this->set($key, $next, $ttl);

        return $next;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function add(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->set($key, $value, $ttlSeconds);
    }

    private function remainingTtl(string $key): ?int
    {
        $full = $this->fullKey($key);
        if (!isset($this->items[$full])) {
            return null;
        }
        $expiry = $this->items[$full]['expiry'];
        if ($expiry === 0) {
            return null;
        }

        return max(0, $expiry - time());
    }

    private function fullKey(string $key): string
    {
        return $this->prefix . $key;
    }

    private function touch(string $full): void
    {
        $this->access[$full] = ++$this->clock;
    }

    private function evictIfNeeded(): void
    {
        if ($this->maxItems === null || $this->maxItems <= 0) {
            return;
        }

        while (count($this->items) > $this->maxItems) {
            $victim = null;
            $min = PHP_INT_MAX;
            foreach ($this->access as $k => $stamp) {
                if ($stamp < $min) {
                    $min = $stamp;
                    $victim = $k;
                }
            }
            if ($victim === null) {
                break;
            }
            unset($this->items[$victim], $this->access[$victim]);
        }
    }
}

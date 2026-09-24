<?php

declare(strict_types=1);

namespace Loongs\Cache\Store;

use Loongs\Cache\Contract\CacheStoreInterface;
use Loongs\Cache\Exception\CacheException;
use Loongs\Cache\Support\Serializer;
use Swoole\Table;

/**
 * Cross-worker shared memory via Swoole\Table.
 *
 * Limits (Swoole Table):
 * - Fixed row count set at create time (cannot grow).
 * - Value column is a fixed-size string; oversized payloads are rejected.
 * - Key length is capped by Table::TYPE (default 64 bytes for the row key).
 * - Survives within one server master lifetime; not durable across restarts.
 */
final class TableStore implements CacheStoreInterface
{
    private Table $table;

    public function __construct(
        private readonly string $prefix = '',
        int $size = 1024,
        int $valueSize = 4096,
        ?Table $table = null,
    ) {
        if ($table !== null) {
            $this->table = $table;
            return;
        }

        if ($size < 1) {
            throw new CacheException('Swoole Table size must be >= 1.');
        }

        $t = new Table($size);
        $t->column('payload', Table::TYPE_STRING, $valueSize);
        $t->column('expiry', Table::TYPE_INT);
        if (!$t->create()) {
            throw new CacheException('Failed to create Swoole\\Table for cache.');
        }
        $this->table = $t;
    }

    public function get(string $key): mixed
    {
        $row = $this->table->get($this->fullKey($key));
        if ($row === false) {
            return null;
        }

        $expiry = (int) ($row['expiry'] ?? 0);
        if ($expiry !== 0 && $expiry <= time()) {
            $this->table->del($this->fullKey($key));
            return null;
        }

        return Serializer::decode((string) $row['payload']);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $payload = Serializer::encode($value);
        $expiry = $ttlSeconds === null ? 0 : time() + $ttlSeconds;

        return $this->table->set($this->fullKey($key), [
            'payload' => $payload,
            'expiry' => $expiry,
        ]);
    }

    public function delete(string $key): bool
    {
        return $this->table->del($this->fullKey($key));
    }

    public function clear(): bool
    {
        foreach ($this->table as $key => $_row) {
            if ($this->prefix === '' || str_starts_with((string) $key, $this->prefix)) {
                $this->table->del((string) $key);
            }
        }

        return true;
    }

    public function has(string $key): bool
    {
        $row = $this->table->get($this->fullKey($key));
        if ($row === false) {
            return false;
        }
        $expiry = (int) ($row['expiry'] ?? 0);
        if ($expiry !== 0 && $expiry <= time()) {
            $this->table->del($this->fullKey($key));
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
        $ok = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttlSeconds)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function deleteMultiple(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $ok = false;
            }
        }

        return $ok;
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
        if (!$this->set($key, $next, $ttl)) {
            return false;
        }

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
        $row = $this->table->get($this->fullKey($key));
        if ($row === false) {
            return null;
        }
        $expiry = (int) ($row['expiry'] ?? 0);
        if ($expiry === 0) {
            return null;
        }

        return max(0, $expiry - time());
    }

    private function fullKey(string $key): string
    {
        return $this->prefix . $key;
    }
}

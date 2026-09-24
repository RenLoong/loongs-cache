<?php

declare(strict_types=1);

namespace Loongs\Cache\Store;

use Loongs\Cache\Contract\CacheStoreInterface;
use Loongs\Cache\Contract\RedisRunnerInterface;
use Loongs\Cache\Support\Serializer;
use Redis;

/**
 * Redis-backed cache using a pooled runner (borrow/return per operation).
 * clear() uses SCAN on the key prefix — never FLUSHDB.
 */
final class RedisStore implements CacheStoreInterface
{
    public function __construct(
        private readonly RedisRunnerInterface $runner,
        private readonly string $prefix = 'loong:cache:',
    ) {
    }

    public function get(string $key): mixed
    {
        $raw = $this->runner->run(function (Redis $r) use ($key): mixed {
            return $r->get($this->fullKey($key));
        });

        if ($raw === false || $raw === null) {
            return null;
        }

        return Serializer::decode((string) $raw);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $payload = Serializer::encode($value);
        $full = $this->fullKey($key);

        return (bool) $this->runner->run(static function (Redis $r) use ($full, $payload, $ttlSeconds): mixed {
            if ($ttlSeconds === null) {
                return $r->set($full, $payload);
            }
            if ($ttlSeconds === 0) {
                // Expire immediately — store then expire, or just skip
                $r->set($full, $payload);
                return $r->expire($full, 0);
            }

            return $r->setex($full, $ttlSeconds, $payload);
        });
    }

    public function delete(string $key): bool
    {
        return (bool) $this->runner->run(function (Redis $r) use ($key): mixed {
            return $r->del($this->fullKey($key));
        });
    }

    public function clear(): bool
    {
        return (bool) $this->runner->run(function (Redis $r): bool {
            $pattern = $this->prefix . '*';
            $iterator = null;
            do {
                $keys = $r->scan($iterator, $pattern, 100);
                if ($keys === false) {
                    break;
                }
                if (is_array($keys) && $keys !== []) {
                    $r->del(...$keys);
                }
            } while ($iterator !== 0 && $iterator !== null && (string) $iterator !== '0');

            return true;
        });
    }

    public function has(string $key): bool
    {
        return (bool) $this->runner->run(function (Redis $r) use ($key): mixed {
            return $r->exists($this->fullKey($key));
        });
    }

    public function getMultiple(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $fullKeys = array_map(fn (string $k): string => $this->fullKey($k), $keys);

        /** @var list<string|false>|false $rawList */
        $rawList = $this->runner->run(static function (Redis $r) use ($fullKeys): mixed {
            return $r->mget($fullKeys);
        });

        $out = [];
        foreach ($keys as $i => $key) {
            $raw = is_array($rawList) ? ($rawList[$i] ?? false) : false;
            $out[$key] = ($raw === false || $raw === null)
                ? null
                : Serializer::decode((string) $raw);
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
        if ($keys === []) {
            return true;
        }

        $fullKeys = array_map(fn (string $k): string => $this->fullKey($k), $keys);

        return (bool) $this->runner->run(static function (Redis $r) use ($fullKeys): mixed {
            return $r->del(...$fullKeys);
        });
    }

    public function increment(string $key, int $value = 1): int|false
    {
        // Values are serialized; do get/modify/set to stay consistent with file/memory.
        $full = $this->fullKey($key);

        return $this->runner->run(function (Redis $r) use ($full, $value): int|false {
            $raw = $r->get($full);
            $current = 0;
            if ($raw !== false && $raw !== null) {
                $decoded = Serializer::decode((string) $raw);
                if (!is_int($decoded) && !is_float($decoded) && !(is_string($decoded) && is_numeric($decoded))) {
                    return false;
                }
                $current = (int) $decoded;
            }
            $next = $current + $value;
            $ttl = $r->ttl($full);
            $payload = Serializer::encode($next);
            if (is_int($ttl) && $ttl > 0) {
                $r->setex($full, $ttl, $payload);
            } else {
                // ttl -1 = no expiry; ttl -2 = missing (treat as forever)
                $r->set($full, $payload);
            }

            return $next;
        });
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function add(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $payload = Serializer::encode($value);
        $full = $this->fullKey($key);

        return (bool) $this->runner->run(static function (Redis $r) use ($full, $payload, $ttlSeconds): mixed {
            if ($ttlSeconds === null) {
                // SET NX
                return $r->set($full, $payload, ['nx']);
            }

            // SET NX EX
            return $r->set($full, $payload, ['nx', 'ex' => $ttlSeconds]);
        });
    }

    private function fullKey(string $key): string
    {
        return $this->prefix . $key;
    }
}

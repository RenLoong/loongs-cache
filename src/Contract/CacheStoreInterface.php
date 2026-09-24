<?php

declare(strict_types=1);

namespace Loongs\Cache\Contract;

/**
 * Low-level store driver. Values are already serialized by the repository
 * when using Repository; drivers that call serialize themselves receive raw PHP values.
 *
 * Drivers in this package receive raw PHP values and handle serialize()/unserialize()
 * themselves so increment works on integers.
 */
interface CacheStoreInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, ?int $ttlSeconds): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    public function has(string $key): bool;

    /**
     * @param list<string> $keys
     * @return array<string, mixed> map of key => value (missing keys omitted or null)
     */
    public function getMultiple(array $keys): array;

    /**
     * @param array<string, mixed> $values
     */
    public function setMultiple(array $values, ?int $ttlSeconds): bool;

    /**
     * @param list<string> $keys
     */
    public function deleteMultiple(array $keys): bool;

    public function increment(string $key, int $value = 1): int|false;

    public function decrement(string $key, int $value = 1): int|false;

    /** Set only if the key does not already exist (or is expired). */
    public function add(string $key, mixed $value, ?int $ttlSeconds): bool;
}

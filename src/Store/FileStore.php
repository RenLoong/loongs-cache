<?php

declare(strict_types=1);

namespace Loongs\Cache\Store;

use Loongs\Cache\Contract\CacheStoreInterface;
use Loongs\Cache\Exception\CacheException;
use Loongs\Cache\Support\Serializer;

/**
 * File-backed cache with hashed sharded paths.
 * Payload format: "{expiryUnix}|{serialized}" where expiry 0 = forever.
 */
final class FileStore implements CacheStoreInterface
{
    private readonly string $directory;

    private readonly string $prefix;

    public function __construct(string $directory, string $prefix = '')
    {
        $this->directory = rtrim($directory, '/\\');
        $this->prefix = $prefix;
        $this->ensureDirectory($this->directory);
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $parsed = $this->parse($raw);
        if ($parsed === null) {
            @unlink($path);
            return null;
        }

        [$expiry, $payload] = $parsed;
        if ($expiry !== 0 && $expiry <= time()) {
            @unlink($path);
            return null;
        }

        return Serializer::decode($payload);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds): bool
    {
        $path = $this->path($key);
        $this->ensureDirectory(dirname($path));

        $expiry = $ttlSeconds === null ? 0 : time() + $ttlSeconds;
        $contents = $expiry . '|' . Serializer::encode($value);

        return $this->atomicWrite($path, $contents);
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    public function clear(): bool
    {
        if (!is_dir($this->directory)) {
            return true;
        }

        $this->purgeDirectory($this->directory);

        return true;
    }

    public function has(string $key): bool
    {
        return $this->existsUnexpired($key);
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
        if ($this->existsUnexpired($key)) {
            return false;
        }

        return $this->set($key, $value, $ttlSeconds);
    }

    private function existsUnexpired(string $key): bool
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return false;
        }
        $parsed = $this->parse($raw);
        if ($parsed === null) {
            return false;
        }
        [$expiry] = $parsed;
        if ($expiry !== 0 && $expiry <= time()) {
            @unlink($path);
            return false;
        }

        return true;
    }


    private function remainingTtl(string $key): ?int
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $parsed = $this->parse($raw);
        if ($parsed === null) {
            return null;
        }
        [$expiry] = $parsed;
        if ($expiry === 0) {
            return null;
        }
        $left = $expiry - time();

        return max(0, $left);
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $this->prefix . $key);
        $shard = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);

        return $this->directory . '/' . $shard . '/' . $hash;
    }

    /** @return array{0: int, 1: string}|null */
    private function parse(string $raw): ?array
    {
        $pos = strpos($raw, '|');
        if ($pos === false) {
            return null;
        }
        $expiryStr = substr($raw, 0, $pos);
        if ($expiryStr === '' || !ctype_digit($expiryStr)) {
            return null;
        }

        return [(int) $expiryStr, substr($raw, $pos + 1)];
    }

    private function atomicWrite(string $path, string $contents): bool
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);
        $tmp = $dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';

        $written = @file_put_contents($tmp, $contents, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            throw new CacheException("Unable to write cache file [{$path}].");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new CacheException("Unable to rename cache file into place [{$path}].");
        }

        return true;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new CacheException("Unable to create cache directory [{$dir}].");
        }
    }

    private function purgeDirectory(string $dir): void
    {
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->purgeDirectory($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}

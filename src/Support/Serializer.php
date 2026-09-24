<?php

declare(strict_types=1);

namespace Loongs\Cache\Support;

use Loongs\Cache\Exception\CacheException;

final class Serializer
{
    public static function encode(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Throwable $e) {
            throw new CacheException('Failed to serialize cache value: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function decode(string $payload): mixed
    {
        if ($payload === '') {
            return null;
        }

        $value = @unserialize($payload, ['allowed_classes' => true]);
        if ($value === false && $payload !== serialize(false)) {
            throw new CacheException('Failed to unserialize cache payload.');
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace Loongs\Cache\Support;

use Loongs\Cache\Exception\InvalidArgumentException;

final class KeyValidator
{
    public static function assert(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Cache key must be a non-empty string.');
        }

        // PSR-16 reserved characters: {}()/\@:
        if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw new InvalidArgumentException(
                "Cache key [{$key}] contains reserved characters {}()/\\@:."
            );
        }
    }

    /**
     * @param iterable<mixed> $keys
     * @return list<string>
     */
    public static function assertList(iterable $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Cache key must be a string.');
            }
            self::assert($key);
            $out[] = $key;
        }

        return $out;
    }
}

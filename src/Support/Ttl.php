<?php

declare(strict_types=1);

namespace Loongs\Cache\Support;

use DateInterval;
use DateTimeImmutable;
use Loongs\Cache\Exception\InvalidArgumentException;

final class Ttl
{
    /**
     * Normalize TTL to non-negative seconds, or null for "no expiry".
     * DateInterval is converted relative to now.
     */
    public static function toSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();
            $target = $now->add($ttl);
            $seconds = $target->getTimestamp() - $now->getTimestamp();
            if ($seconds < 0) {
                throw new InvalidArgumentException('TTL DateInterval must not be negative.');
            }

            return $seconds;
        }

        if ($ttl < 0) {
            throw new InvalidArgumentException('TTL seconds must be >= 0 (or null for forever).');
        }

        return $ttl;
    }
}

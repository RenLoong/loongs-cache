<?php

declare(strict_types=1);

namespace Loongs\Cache\Exception;

use Psr\SimpleCache\CacheException as PsrCacheException;

class CacheException extends \RuntimeException implements PsrCacheException
{
}

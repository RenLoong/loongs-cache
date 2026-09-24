# loongs/cache

Standalone cache package for loong-swoole (PHP 8.4 + Swoole 6).

## Drivers

| Driver | Scope | Notes |
|--------|-------|-------|
| `file` | shared via filesystem | hashed shards under `runtime/cache`, atomic write |
| `redis` | shared via Redis | pooled via `Loongs\Redis\RedisManager` or a callable runner; `clear()` uses SCAN |
| `memory` | **per worker** | in-process array; optional LRU `max_items` |
| `table` | cross-worker | `Swoole\Table`; fixed size / value length limits |

## Usage

```php
use Loongs\Cache\CacheManager;

$cache = cache();                    // CacheManager
cache('greeting', 'hello');          // get with default? → wait: cache('key', default)
cache(['user_1' => $dto], 3600);     // set

$store = cache()->store('redis');
$store->remember('report', 60, fn () => expensive());
$store->add('lock', 1, 10);
$store->increment('hits');
```

PSR-16: `Loongs\Cache\Repository` implements `Psr\SimpleCache\CacheInterface`.
Logical keys must not contain `{}()/\@:` (PSR-16). Prefix (e.g. `loong:cache:`) is applied by stores.

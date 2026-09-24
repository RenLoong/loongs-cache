<?php

declare(strict_types=1);

use Loongs\Cache\CacheManager;
use Loongs\Cache\Repository;

if (!function_exists('cache')) {
    /**
     * Cache helper.
     *
     * - cache()                  → CacheManager
     * - cache('key')             → get
     * - cache('key', $default)   → get with default
     * - cache(['k' => $v], $ttl) → setMultiple / set each
     *
     * Resolves CacheManager from $GLOBALS['__loongs_app']->container() when present.
     */
    function cache(null|string|array $key = null, mixed $default = null): mixed
    {
        $manager = resolve_loongs_cache_manager();

        if ($key === null) {
            return $manager;
        }

        if (is_array($key)) {
            $ttl = $default;
            if (!(is_int($ttl) || $ttl instanceof DateInterval || $ttl === null)) {
                $ttl = null;
            }
            /** @var null|int|DateInterval $ttl */
            foreach ($key as $k => $v) {
                $manager->set((string) $k, $v, $ttl);
            }

            return true;
        }

        return $manager->get($key, $default);
    }
}

if (!function_exists('resolve_loongs_cache_manager')) {
    function resolve_loongs_cache_manager(): CacheManager
    {
        $app = $GLOBALS['__loongs_app'] ?? null;
        if (is_object($app) && method_exists($app, 'container')) {
            $container = $app->container();
            if (is_object($container) && method_exists($container, 'make')) {
                $resolved = $container->make(CacheManager::class);
                if ($resolved instanceof CacheManager) {
                    return $resolved;
                }
            }
        }

        if (isset($GLOBALS['__loongs_cache']) && $GLOBALS['__loongs_cache'] instanceof CacheManager) {
            return $GLOBALS['__loongs_cache'];
        }

        throw new RuntimeException(
            'CacheManager is not available. Boot the application or set $GLOBALS[\'__loongs_cache\'].'
        );
    }
}

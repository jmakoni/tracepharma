<?php

namespace App\Support\Epcis;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Locks for inbound receive / enqueue / sync process.
 *
 * Do not use the file cache store: artisan (jmakoni) and php-fpm (www-data)
 * share storage/framework/cache/data. Existing SHA1 shards are often
 * jmakoni:jmakoni 0775, Laravel FileStore @mkdir is silent, and fopen ENOENT
 * surfaces as "Upload failed". Tenant DBs have no cache_locks table, and
 * Cache::__call is tagged — call store() by name instead.
 */
final class EpcisCacheLock
{
    public static function storeName(): string
    {
        $name = (string) config('cache.default');

        if ($name === 'file') {
            return is_array(config('cache.stores.redis')) ? 'redis' : 'database';
        }

        return $name;
    }

    public static function store(): Repository
    {
        $repository = Cache::store(self::storeName());

        if (! $repository instanceof Repository) {
            throw new \RuntimeException('Epcis cache store must be an Illuminate cache repository.');
        }

        return $repository;
    }

    public static function lock(string $name, int $seconds): Lock
    {
        $store = self::store();

        if ($store instanceof LockProvider) {
            return $store->lock($name, $seconds);
        }

        $inner = self::underlyingStore($store);

        if ($inner instanceof LockProvider) {
            return $inner->lock($name, $seconds);
        }

        throw new \RuntimeException('Epcis cache store must support atomic locks.');
    }

    private static function underlyingStore(Repository $repository): mixed
    {
        if (! method_exists($repository, 'getStore')) {
            return null;
        }

        return $repository->getStore();
    }
}

<?php

namespace App\Support\Epcis;

use Illuminate\Contracts\Cache\Repository;
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
        return Cache::store(self::storeName());
    }
}

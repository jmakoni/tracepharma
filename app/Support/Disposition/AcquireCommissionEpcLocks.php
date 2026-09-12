<?php

declare(strict_types=1);

namespace App\Support\Disposition;

use App\Support\Epcis\EpcisCacheLock;
use Illuminate\Contracts\Cache\Lock;

/**
 * One tenant-scoped lock per EPC so overlapping commission sets serialize
 * on shared units. Sorted acquisition avoids deadlocks.
 */
final class AcquireCommissionEpcLocks
{
    public function __construct(
        private readonly int $ttlSeconds = 60,
        private readonly int $waitSeconds = 30,
    ) {}

    public static function key(string $tenantId, int $epcId): string
    {
        return 'commission-epc:'.$tenantId.':'.$epcId;
    }

    /**
     * @param  list<int>  $epcIds
     * @return list<Lock>
     */
    public function acquire(array $epcIds): array
    {
        $sortedIds = array_values(array_unique(array_filter(
            array_map(intval(...), $epcIds),
            fn (int $id): bool => $id > 0,
        )));
        sort($sortedIds);

        if ($sortedIds === []) {
            return [];
        }

        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        /** @var list<Lock> $acquired */
        $acquired = [];

        try {
            foreach ($sortedIds as $epcId) {
                $lock = EpcisCacheLock::lock(self::key($tenantId, $epcId), $this->ttlSeconds);
                $lock->block($this->waitSeconds);
                $acquired[] = $lock;
            }
        } catch (\Throwable $e) {
            $this->release($acquired);
            throw $e;
        }

        return $acquired;
    }

    /**
     * @param  list<Lock>  $locks
     */
    public function release(array $locks): void
    {
        foreach ($locks as $lock) {
            $lock->release();
        }
    }
}

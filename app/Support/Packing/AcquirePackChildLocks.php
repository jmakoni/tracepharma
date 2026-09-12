<?php

namespace App\Support\Packing;

use App\Support\Epcis\EpcisCacheLock;
use Illuminate\Contracts\Cache\Lock;

/**
 * One tenant-scoped lock per child EPC so concurrent pack / unpack / break-pack
 * operators cannot claim the same unit from two workstations. Sorted acquisition
 * avoids deadlocks when lock sets overlap.
 */
final class AcquirePackChildLocks
{
    private const SOFT_TTL_SECONDS = 30;

    /**
     * @param  list<int>  $childIds
     * @return list<Lock>|null Null when any child is already locked; nothing stays held.
     */
    public function acquire(array $childIds): ?array
    {
        $sortedIds = array_values(array_unique($childIds));
        sort($sortedIds);

        if ($sortedIds === []) {
            return [];
        }

        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        /** @var list<Lock> $acquired */
        $acquired = [];

        foreach ($sortedIds as $epcId) {
            $lock = EpcisCacheLock::lock('pack-child:'.$tenantId.':'.$epcId, 600);

            if (! $lock->get()) {
                foreach ($acquired as $held) {
                    $held->release();
                }

                return null;
            }

            $acquired[] = $lock;
        }

        return $acquired;
    }

    /**
     * Pack confirmation lock set: children plus the bound parent SSCC when present.
     *
     * @param  list<int>  $childIds
     * @return list<Lock>|null
     */
    public function acquireForPack(array $childIds, ?int $parentEpcId = null): ?array
    {
        $ids = $childIds;
        if ($parentEpcId !== null && $parentEpcId > 0) {
            $ids[] = $parentEpcId;
        }

        return $this->acquire($ids);
    }

    public function softReserve(int $childId): bool
    {
        if ($childId <= 0) {
            return false;
        }

        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');

        return EpcisCacheLock::lock('pack-child-soft:'.$tenantId.':'.$childId, self::SOFT_TTL_SECONDS)->get();
    }

    public function releaseSoftReserve(int $childId): void
    {
        if ($childId <= 0) {
            return;
        }

        $tenantId = (string) (tenant()?->getKey() ?? 'unknown');
        EpcisCacheLock::lock('pack-child-soft:'.$tenantId.':'.$childId, self::SOFT_TTL_SECONDS)->forceRelease();
    }

    /**
     * @param  list<int>  $childIds
     */
    public function releaseSoftReserves(array $childIds): void
    {
        foreach (array_unique($childIds) as $childId) {
            $this->releaseSoftReserve((int) $childId);
        }
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

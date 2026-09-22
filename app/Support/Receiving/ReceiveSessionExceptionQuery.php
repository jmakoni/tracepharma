<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReceiveSessionExceptionQuery
{
    /**
     * @param  list<string>  $typeCodes
     * @return Collection<int, ExceptionCase>
     */
    public static function openCases(ReceivingSession $session, array $typeCodes = []): Collection
    {
        $query = ExceptionCase::query()
            ->open()
            ->with('type')
            ->whereHas('activities', function (Builder $activities) use ($session): void {
                $activities->where(function (Builder $meta) use ($session): void {
                    $meta->where('meta->receiving_session_id', (int) $session->getKey())
                        ->orWhere('meta->receiving_session_id', (string) $session->getKey());
                });
            });

        if ($typeCodes !== []) {
            $query->whereHas('type', fn (Builder $types) => $types->whereIn('code', $typeCodes));
        }

        return $query->latest('id')->get();
    }

    /**
     * @return array{shortage: int, no_data: int, quarantine: int}
     */
    public static function floorBadgeCounts(ReceivingSession $session): array
    {
        $shortage = self::openCases($session, ReceiveExceptionTypes::SHORT_CLOSE_REQUIRED)->count();
        $noData = self::openCases($session, [ReceiveExceptionTypes::PRODUCT_NO_DATA])->count();

        $quarantine = QuarantineHold::query()
            ->open()
            ->where(function (Builder $query) use ($session): void {
                $query->where('meta->receiving_session_id', (int) $session->getKey())
                    ->orWhere('meta->receiving_session_id', (string) $session->getKey());
            })
            ->count();

        return [
            'shortage' => $shortage,
            'no_data' => $noData,
            'quarantine' => $quarantine,
        ];
    }
}

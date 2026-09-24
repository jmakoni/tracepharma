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
        return self::constrainToSession(
            ExceptionCase::query()->open()->with('type'),
            (int) $session->getKey(),
            $typeCodes,
        )->latest('id')->get();
    }

    /**
     * @param  Builder<ExceptionCase>  $query
     * @param  list<string>  $typeCodes
     * @return Builder<ExceptionCase>
     */
    public static function constrainToSession(Builder $query, int $sessionId, array $typeCodes = []): Builder
    {
        if ($sessionId <= 0) {
            return $query;
        }

        $query->whereHas('activities', function (Builder $activities) use ($sessionId): void {
            $activities->where(function (Builder $meta) use ($sessionId): void {
                $meta->where('meta->receiving_session_id', $sessionId)
                    ->orWhere('meta->receiving_session_id', (string) $sessionId);
            });
        });

        if ($typeCodes !== []) {
            $query->whereHas('type', fn (Builder $types) => $types->whereIn('code', $typeCodes));
        }

        return $query;
    }

    public static function sessionIdForCase(ExceptionCase $case): ?int
    {
        if ($case->relationLoaded('activities')) {
            foreach ($case->activities as $activity) {
                $raw = $activity->meta['receiving_session_id'] ?? null;
                if ($raw !== null && $raw !== '') {
                    return (int) $raw;
                }
            }
        }

        $activities = $case->activities()->orderByDesc('id')->get(['meta']);
        foreach ($activities as $activity) {
            $raw = $activity->meta['receiving_session_id'] ?? null;
            if ($raw !== null && $raw !== '') {
                return (int) $raw;
            }
        }

        return null;
    }

    /**
     * Badge key → exception type codes. Empty list means session-only (quarantine holds).
     *
     * @return array<string, list<string>>
     */
    public static function badgeTypeCodes(): array
    {
        return [
            'shortage' => ReceiveExceptionTypes::SHORT_CLOSE_REQUIRED,
            'no_data' => [ReceiveExceptionTypes::PRODUCT_NO_DATA],
            'quarantine' => [],
            'mismatch' => [ReceiveExceptionTypes::PI_MISMATCH],
            'overage' => [ReceiveExceptionTypes::OVERAGE],
            'wrong_site' => [ReceiveExceptionTypes::WRONG_DESTINATION],
            'document_hold' => [ReceiveExceptionTypes::LATE_FAILED_EPCIS],
            'wrong_item' => [ReceiveExceptionTypes::WRONG_ITEM],
            'damaged' => [ReceiveExceptionTypes::DAMAGED],
        ];
    }

    /**
     * @return array{shortage: int, no_data: int, quarantine: int, mismatch: int, overage: int, wrong_site: int, document_hold: int, wrong_item: int, damaged: int}
     */
    public static function floorBadgeCounts(ReceivingSession $session): array
    {
        $shortage = self::openCases($session, ReceiveExceptionTypes::SHORT_CLOSE_REQUIRED)->count();
        $noData = self::openCases($session, [ReceiveExceptionTypes::PRODUCT_NO_DATA])->count();
        $mismatch = self::openCases($session, [ReceiveExceptionTypes::PI_MISMATCH])->count();
        $overage = self::openCases($session, [ReceiveExceptionTypes::OVERAGE])->count();
        $wrongSite = self::openCases($session, [ReceiveExceptionTypes::WRONG_DESTINATION])->count();
        $documentHold = self::openCases($session, [ReceiveExceptionTypes::LATE_FAILED_EPCIS])->count();
        $wrongItem = self::openCases($session, [ReceiveExceptionTypes::WRONG_ITEM])->count();
        $damaged = self::openCases($session, [ReceiveExceptionTypes::DAMAGED])->count();

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
            'mismatch' => $mismatch,
            'overage' => $overage,
            'wrong_site' => $wrongSite,
            'document_hold' => $documentHold,
            'wrong_item' => $wrongItem,
            'damaged' => $damaged,
        ];
    }

    /**
     * @return array{shortage: int, no_data: int, quarantine: int, mismatch: int, overage: int, wrong_site: int, document_hold: int, wrong_item: int, damaged: int}
     */
    public static function emptyBadgeCounts(): array
    {
        return [
            'shortage' => 0,
            'no_data' => 0,
            'quarantine' => 0,
            'mismatch' => 0,
            'overage' => 0,
            'wrong_site' => 0,
            'document_hold' => 0,
            'wrong_item' => 0,
            'damaged' => 0,
        ];
    }
}

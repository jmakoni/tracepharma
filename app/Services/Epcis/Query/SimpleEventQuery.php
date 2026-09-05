<?php

declare(strict_types=1);

namespace App\Services\Epcis\Query;

use App\Models\Epcis\EpcisEvent;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Epcis\Validation\EpcisCbv20Mapper;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * GS1 SimpleEventQuery subset → Eloquent on epcis_events.
 */
final class SimpleEventQuery
{
    /** @var list<string> */
    public const ALLOWED_PARAMS = [
        'EQ_bizStep',
        'GE_eventTime',
        'LE_eventTime',
        'MATCH_epc',
        'EQ_action',
        'perPage',
        'nextPageToken',
    ];

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws InvalidArgumentException
     */
    public function assertAllowedParams(array $params): void
    {
        $unknown = array_diff(array_keys($params), self::ALLOWED_PARAMS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'QueryParameterException: unsupported query parameter(s): '.implode(', ', $unknown),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{events: list<EpcisEvent>, nextPageToken: ?string, perPage: int}
     */
    public function execute(array $params, ?User $user = null): array
    {
        $this->assertAllowedParams($params);

        $perPage = min(200, max(1, (int) ($params['perPage'] ?? 50)));

        $query = EpcisEvent::query()
            ->with([
                'eventEpcs.epc',
                'locations',
                'bizTransactions',
                'epcIlmd',
                'document',
            ])
            ->whereExists(function ($sub) use ($user): void {
                $sub->selectRaw('1')
                    ->from('epcis_documents')
                    ->whereColumn('epcis_documents.id', 'epcis_events.document_id')
                    ->whereColumn('epcis_documents.ingest_generation', 'epcis_events.ingest_generation')
                    ->whereIn('epcis_documents.status', ['parsed', 'validated', 'generated']);

                if ($user !== null && ! $user->can(Permissions::SitesAccessAll)) {
                    $siteIds = SiteAccess::userSiteIds($user);
                    $sub->where(function ($scope) use ($siteIds): void {
                        $scope->whereIn('epcis_documents.ship_to_site_id', $siteIds)
                            ->orWhereIn('epcis_documents.ship_from_site_id', $siteIds);
                    });
                }
            });

        if (filled($params['EQ_bizStep'] ?? null)) {
            $bizStep = EpcisCbv20Mapper::toCanonicalBizStep((string) $params['EQ_bizStep'])
                ?? (string) $params['EQ_bizStep'];
            $query->where('biz_step', $bizStep);
        }

        if (filled($params['EQ_action'] ?? null)) {
            $query->where('action', strtoupper(trim((string) $params['EQ_action'])));
        }

        if (filled($params['GE_eventTime'] ?? null)) {
            $query->where('event_time', '>=', (string) $params['GE_eventTime']);
        }

        if (filled($params['LE_eventTime'] ?? null)) {
            $query->where('event_time', '<=', (string) $params['LE_eventTime']);
        }

        if (filled($params['MATCH_epc'] ?? null)) {
            $epc = (string) $params['MATCH_epc'];
            $query->whereHas('eventEpcs.epc', fn (Builder $epcQuery) => $epcQuery->where('epc_uri', $epc));
        }

        if (filled($params['nextPageToken'] ?? null)) {
            $afterId = $this->decodePageToken((string) $params['nextPageToken']);
            if ($afterId !== null) {
                $query->where('id', '>', $afterId);
            }
        }

        $events = $query->orderBy('id')->limit($perPage + 1)->get();
        $hasMore = $events->count() > $perPage;
        if ($hasMore) {
            $events = $events->take($perPage)->values();
        }

        $nextToken = null;
        if ($hasMore && $events->isNotEmpty()) {
            $nextToken = $this->encodePageToken((int) $events->last()->getKey());
        }

        return [
            'events' => $events->all(),
            'nextPageToken' => $nextToken,
            'perPage' => $perPage,
        ];
    }

    public function encodePageToken(int $eventId): string
    {
        return rtrim(strtr(base64_encode((string) $eventId), '+/', '-_'), '=');
    }

    public function decodePageToken(string $token): ?int
    {
        $padded = strtr($token, '-_', '+/');
        $padLen = (4 - (strlen($padded) % 4)) % 4;
        $decoded = base64_decode($padded.str_repeat('=', $padLen), true);
        if ($decoded === false || ! ctype_digit($decoded)) {
            return null;
        }

        return (int) $decoded;
    }
}

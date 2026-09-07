<?php

declare(strict_types=1);

namespace App\Support\Integrations;

use App\Enums\OutboundConformanceState;
use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;
use DomainException;

/**
 * Kind / partner / transport matrix for outbound connections (provider hubs).
 */
final class OutboundConnectionKindValidator
{
    /**
     * @param  list<int>  $partnerIds
     */
    public static function assertSavable(
        OutboundConnectionKind $kind,
        SerializationProvider $provider,
        OutboundTransport $transport,
        array $partnerIds,
        bool $isSystem = false,
    ): void {
        if ($isSystem) {
            return;
        }

        OutboundSendPreset::assertTransportAllowed($kind, $provider, $transport);

        $count = count(array_unique(array_map('intval', $partnerIds)));

        match ($kind) {
            OutboundConnectionKind::ProviderHub => self::assertHub($provider, $count),
            OutboundConnectionKind::DirectPartner => self::assertDirect($provider, $count),
            OutboundConnectionKind::LocalDelivery => self::assertLocal($transport),
        };
    }

    private static function assertHub(SerializationProvider $provider, int $partnerCount): void
    {
        if (! OutboundSendPreset::isNetworkProvider($provider) && $provider !== SerializationProvider::Other) {
            throw new DomainException(
                'Provider hub requires a network serialization provider (LSPediA, UniTrace, TraceLink, …).',
            );
        }

        if ($partnerCount < 1) {
            throw new DomainException(
                'Provider hubs must assign at least one trading partner. Empty partners are only for portal/email.',
            );
        }
    }

    private static function assertDirect(SerializationProvider $provider, int $partnerCount): void
    {
        if ($partnerCount !== 1) {
            throw new DomainException(
                'Direct partner connections must assign exactly one trading partner.',
            );
        }

        if (OutboundSendPreset::isNetworkProvider($provider)
            && ! in_array($provider, [
                SerializationProvider::CustomHttps,
                SerializationProvider::CustomAs2,
            ], true)
        ) {
            throw new DomainException(
                'Network providers (LSPediA, UniTrace, …) must use Provider hub, not Direct partner.',
            );
        }
    }

    private static function assertLocal(OutboundTransport $transport): void
    {
        if (! in_array($transport, [OutboundTransport::Email, OutboundTransport::Portal], true)) {
            throw new DomainException(
                'Local delivery must use Email or Client portal transport.',
            );
        }
    }

    /**
     * Fail when another active hub in the same conformance band already lists any of these partners.
     *
     * @param  list<int>  $partnerIds
     */
    public static function assertNoAmbiguousHubMembership(
        OutboundConnection $connection,
        array $partnerIds,
        OutboundConformanceState $conformance,
    ): void {
        $partnerIds = array_values(array_unique(array_filter(
            array_map('intval', $partnerIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($partnerIds === []) {
            return;
        }

        $band = self::conformanceBand($conformance);

        $conflicts = OutboundConnection::query()
            ->whereKeyNot($connection->exists ? $connection->getKey() : 0)
            ->where('is_active', true)
            ->whereIn('conformance_state', $band)
            ->whereHas('tradingPartners', fn ($q) => $q->whereIn('trading_partners.id', $partnerIds))
            ->whereIn('transport', [
                OutboundTransport::Https->value,
                OutboundTransport::Sftp->value,
                OutboundTransport::As2->value,
            ])
            ->with(['tradingPartners:id,name'])
            ->get();

        if ($conflicts->isEmpty()) {
            return;
        }

        $names = $conflicts->flatMap(
            fn (OutboundConnection $c) => $c->tradingPartners
                ->filter(fn ($p) => in_array((int) $p->getKey(), $partnerIds, true))
                ->pluck('name'),
        )->unique()->values()->implode(', ');

        throw new DomainException(
            'ambiguous_outbound_hub: partner(s) already assigned to another active hub in this conformance band: '
            .($names !== '' ? $names : 'overlapping partners')
            .'. Move them (detach the other hub) before saving.',
        );
    }

    /**
     * @return list<string>
     */
    public static function conformanceBand(OutboundConformanceState $state): array
    {
        if ($state === OutboundConformanceState::Live
            || $state === OutboundConformanceState::Hypercare
            || $state === OutboundConformanceState::FirstLiveLot
        ) {
            return [
                OutboundConformanceState::FirstLiveLot->value,
                OutboundConformanceState::Hypercare->value,
                OutboundConformanceState::Live->value,
            ];
        }

        return [
            OutboundConformanceState::Test->value,
            OutboundConformanceState::Conformance->value,
        ];
    }
}

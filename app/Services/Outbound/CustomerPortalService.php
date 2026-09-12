<?php

namespace App\Services\Outbound;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\EpcisDocument;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues signed buyer-portal links. Token is customer_portal_uuid — never
 * portal_share_uuid (supplier exception portal).
 */
final class CustomerPortalService
{
    private const DEFAULT_TTL_DAYS = 30;

    public function ensureCustomerPortalLink(TradingPartner $partner): TradingPartner
    {
        if ($partner->customer_portal_uuid === null) {
            $partner->forceFill([
                'customer_portal_uuid' => (string) Str::uuid(),
            ])->save();

            $this->logPortalChange($partner, 'customer_portal_link_issued');
        }

        return $partner->refresh();
    }

    public function rotateCustomerPortalLink(TradingPartner $partner): TradingPartner
    {
        $partner->forceFill([
            'customer_portal_uuid' => (string) Str::uuid(),
        ])->save();

        $this->logPortalChange($partner, 'customer_portal_link_rotated');

        return $partner->refresh();
    }

    public function revokeCustomerPortalLink(TradingPartner $partner): TradingPartner
    {
        if ($partner->customer_portal_uuid === null) {
            return $partner;
        }

        $partner->forceFill(['customer_portal_uuid' => null])->save();
        $this->logPortalChange($partner, 'customer_portal_link_revoked');

        return $partner->refresh();
    }

    /**
     * @throws RuntimeException when the partner is inactive
     */
    public function signedCustomerPortalUrl(TradingPartner $partner): string
    {
        if (! $partner->is_active) {
            throw new RuntimeException('Inactive trading partners cannot be granted a customer portal link.');
        }

        $partner = $this->ensureCustomerPortalLink($partner);

        return URL::temporarySignedRoute(
            'tenant.customer-portal.index',
            now()->addDays($this->linkTtlDays()),
            ['customerPortalUuid' => $partner->customer_portal_uuid],
        );
    }

    public function signedDownloadUrl(TradingPartner $partner, EpcisDocument $document): string
    {
        $partner = $this->ensureCustomerPortalLink($partner);

        return URL::temporarySignedRoute(
            'tenant.customer-portal.download',
            now()->addDays($this->linkTtlDays()),
            [
                'customerPortalUuid' => $partner->customer_portal_uuid,
                'document' => $document->getKey(),
            ],
        );
    }

    /**
     * @return Builder<EpcisDocument>
     */
    public function portalDocumentsQuery(
        TradingPartner $partner,
        ?string $direction = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $po = null,
    ): Builder {
        $years = max(1, (int) config('tracepharma.epcis.retention_years', 6));
        $retentionFrom = now()->subYears($years);
        $from = $from !== null && $from->greaterThan($retentionFrom) ? $from : $retentionFrom;
        $to = $to ?? now();

        $outbound = $this->documentsQuery($partner)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);

        $inbound = $this->inboundDocumentsQuery($partner)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);

        if (filled($po)) {
            $poLike = '%'.$po.'%';
            $outbound->where('customer_po', 'like', $poLike);
            $inbound->where(function (Builder $query) use ($poLike): void {
                $query->where('customer_po', 'like', $poLike)
                    ->orWhere('asn_number', 'like', $poLike);
            });
        }

        if ($direction === 'outbound') {
            return $outbound;
        }

        if ($direction === 'inbound') {
            return $inbound;
        }

        return EpcisDocument::query()
            ->where(function (Builder $query) use ($outbound, $inbound): void {
                $query->whereIn('id', $outbound->select('id'))
                    ->orWhereIn('id', $inbound->select('id'));
            })
            ->latest('id');
    }

    /**
     * Inbound docs visible on the customer portal.
     *
     * Authorization is by trading_partner_id only. Do not OR on sender_gln —
     * that field comes from payload content and can be spoofed on ingest,
     * which would leak another partner's EPCIS to a signed portal holder.
     *
     * @return Builder<EpcisDocument>
     */
    public function inboundDocumentsQuery(TradingPartner $partner): Builder
    {
        $years = max(1, (int) config('tracepharma.epcis.retention_years', 6));
        $partnerId = (int) $partner->getKey();

        return EpcisDocument::query()
            ->where('direction', 'inbound')
            ->where('trading_partner_id', $partnerId)
            ->whereIn('status', ['parsed', 'validated', 'generated'])
            ->where('created_at', '>=', now()->subYears($years))
            ->whereNotNull('payload_path')
            ->latest('id');
    }

    /**
     * @return Builder<EpcisDocument>
     */
    public function documentsQuery(TradingPartner $partner): Builder
    {
        $years = max(1, (int) config('tracepharma.epcis.retention_years', 6));
        $partnerId = (int) $partner->getKey();

        return EpcisDocument::query()
            ->where('direction', 'outbound')
            ->where(function (Builder $query) use ($partnerId): void {
                $query->where('trading_partner_id', $partnerId)
                    ->orWhere('ship_to_partner_id', $partnerId);
            })
            ->where(function (Builder $query): void {
                $query->where('authored_kind', EpcisAuthoredKind::Shipping)
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('authored_kind')
                            ->where(function (Builder $notes): void {
                                $notes->where('notes', 'like', '%Generated outbound shipping%')
                                    ->orWhere('notes', 'like', '%ship order session%');
                            });
                    });
            })
            ->whereNotIn('status', ['error', 'draft'])
            ->where('created_at', '>=', now()->subYears($years))
            ->whereNotNull('payload_path')
            ->latest('id');
    }

    public function linkTtlDays(): int
    {
        return max(1, (int) config(
            'tracepharma.customer_portal.link_ttl_days',
            self::DEFAULT_TTL_DAYS,
        ));
    }

    private function logPortalChange(TradingPartner $partner, string $description): void
    {
        if (! function_exists('activity')) {
            return;
        }

        activity()
            ->performedOn($partner)
            ->withProperties(array_filter([
                'trading_partner_id' => $partner->getKey(),
                'user_id' => auth()->id(),
            ], static fn ($value) => $value !== null))
            ->log($description);
    }
}

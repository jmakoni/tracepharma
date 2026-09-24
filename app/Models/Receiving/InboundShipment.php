<?php

namespace App\Models\Receiving;

use App\Models\Epcis\EpcisDocument;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundShipment extends Model
{
    protected $table = 'inbound_shipments';

    protected $fillable = [
        'trading_partner_id',
        'trading_partner_key',
        'asn_number',
        'customer_po',
        'status',
        'document_count',
        'expected_parent_count',
        'confirmed_parent_count',
        'expected_each_count',
        'confirmed_each_count',
        'unexpected_count',
    ];

    protected function casts(): array
    {
        return [
            'trading_partner_key' => 'integer',
            'document_count' => 'integer',
            'expected_parent_count' => 'integer',
            'confirmed_parent_count' => 'integer',
            'expected_each_count' => 'integer',
            'confirmed_each_count' => 'integer',
            'unexpected_count' => 'integer',
        ];
    }

    public static function partnerKey(?int $tradingPartnerId): int
    {
        return $tradingPartnerId !== null && $tradingPartnerId > 0 ? $tradingPartnerId : 0;
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class, 'trading_partner_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EpcisDocument::class, 'inbound_shipment_id');
    }

    public function receivingSessions(): HasMany
    {
        return $this->hasMany(ReceivingSession::class, 'inbound_shipment_id');
    }

    public function expectedLines(): HasMany
    {
        return $this->hasMany(InboundExpectedLine::class, 'inbound_shipment_id');
    }

    /**
     * Lines still awaiting confirmation (status = expected).
     *
     * @return Builder<InboundExpectedLine>
     */
    public function remainingExpectedLines(): Builder
    {
        return InboundExpectedLine::query()
            ->where('inbound_shipment_id', $this->getKey())
            ->where('status', 'expected');
    }

    public function hasRemainingExpected(): bool
    {
        return $this->remainingExpectedLines()->exists();
    }

    /**
     * True when no lines remain in status=expected (cancelled/unexpected ignored).
     */
    public function isComplete(): bool
    {
        return ! $this->hasRemainingExpected();
    }

    /**
     * Recompute rollup caches from expected lines and update order status.
     *
     * Status rules (cancelled is sticky):
     * - complete when remaining expected = 0 and at least one confirmed
     * - expected when no confirms yet
     * - open when some confirms remain with expected lines left
     */
    public function refreshRollups(): self
    {
        $stats = $this->expectedLines()
            ->toBase()
            ->selectRaw("
                SUM(CASE WHEN line_role = 'parent' AND status IN ('expected', 'confirmed') THEN 1 ELSE 0 END) AS expected_parent,
                SUM(CASE WHEN line_role = 'parent' AND status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_parent,
                SUM(CASE WHEN line_role = 'child' AND status IN ('expected', 'confirmed') THEN 1 ELSE 0 END) AS expected_each,
                SUM(CASE WHEN line_role = 'child' AND status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_each,
                SUM(CASE WHEN status = 'unexpected' THEN 1 ELSE 0 END) AS unexpected_count,
                SUM(CASE WHEN status = 'expected' THEN 1 ELSE 0 END) AS remaining_expected
            ")
            ->first();

        $expectedParent = (int) ($stats->expected_parent ?? 0);
        $confirmedParent = (int) ($stats->confirmed_parent ?? 0);
        $expectedEach = (int) ($stats->expected_each ?? 0);
        $confirmedEach = (int) ($stats->confirmed_each ?? 0);
        $unexpected = (int) ($stats->unexpected_count ?? 0);
        $remainingExpected = (int) ($stats->remaining_expected ?? 0);
        $anyConfirmed = ($confirmedParent + $confirmedEach) > 0;

        $attributes = [
            'expected_parent_count' => $expectedParent,
            'confirmed_parent_count' => $confirmedParent,
            'expected_each_count' => $expectedEach,
            'confirmed_each_count' => $confirmedEach,
            'unexpected_count' => $unexpected,
        ];

        if ($this->status !== 'cancelled') {
            if ($remainingExpected === 0 && $anyConfirmed) {
                $attributes['status'] = 'complete';
            } elseif (! $anyConfirmed) {
                $attributes['status'] = 'expected';
            } else {
                $attributes['status'] = 'open';
            }
        }

        $this->forceFill($attributes)->save();

        return $this;
    }

    public function markCompleteIfDone(): self
    {
        if ($this->status === 'cancelled') {
            return $this;
        }

        if ($this->isComplete() && (
            (int) $this->confirmed_parent_count > 0
            || (int) $this->confirmed_each_count > 0
            || $this->expectedLines()->where('status', 'confirmed')->exists()
        )) {
            if ($this->status !== 'complete') {
                $this->forceFill(['status' => 'complete'])->save();
            }
        }

        return $this;
    }
}

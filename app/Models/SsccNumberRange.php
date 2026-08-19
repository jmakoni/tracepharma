<?php

namespace App\Models;

use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class SsccNumberRange extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name',
        'scope',
        'site_id',
        'trading_partner_id',
        'company_prefix',
        'extension_digit',
        'gs1_api_key',
        'index',
        'increment_by',
        'range_size',
        'start_number',
        'current_number',
        'threshold_percentage',
        'status',
        'remaining',
        'threshold_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => SsccNumberRangeScope::class,
            'status' => SsccNumberRangeStatus::class,
            'gs1_api_key' => 'encrypted',
            'index' => 'integer',
            'increment_by' => 'integer',
            'range_size' => 'integer',
            'start_number' => 'integer',
            'current_number' => 'integer',
            'threshold_percentage' => 'integer',
            'remaining' => 'integer',
            'threshold_notified_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'current_number', 'remaining'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    /**
     * Pointer-based utilization (serials this range has walked), not foreign labels in the band.
     */
    public function utilizedSteps(): int
    {
        $increment = max(1, (int) $this->increment_by);

        return max(0, (int) floor(((int) $this->current_number - (int) $this->start_number) / $increment));
    }

    /**
     * Free serials from current→last on the arithmetic sequence, subtracting on-grid labels.
     * Uses a SQL COUNT (with a modulo filter for the arithmetic grid) instead of pulling every
     * label serial into memory, so this stays O(1) memory even for huge bands.
     */
    public function issuableCount(): int
    {
        $increment = max(1, (int) $this->increment_by);
        $current = (int) $this->current_number;
        $last = $this->lastIssuableNumber();

        if ($current > $last) {
            return 0;
        }

        $totalSlots = (int) floor(($last - $current) / $increment) + 1;

        if (blank($this->company_prefix)) {
            return $totalSlots;
        }

        $usedOnGrid = SsccLabel::query()
            ->where('company_prefix', (string) $this->company_prefix)
            ->where('extension_digit', (string) ($this->extension_digit ?? '0'))
            ->whereBetween('serial_reference_int', [$current, $last])
            ->when($increment > 1, fn (Builder $query) => $query->whereRaw(
                'MOD(serial_reference_int - ?, ?) = 0',
                [$current, $increment],
            ))
            ->count();

        return max(0, $totalSlots - $usedOnGrid);
    }

    public function hasIssuableCapacity(int $need): bool
    {
        return $this->issuableCount() >= max(1, $need);
    }

    /**
     * Inclusive end of the band that must stay reserved (overlap + allocator).
     * Inactive ranges only reserve already-issued serials so the unused tail can be replenished.
     */
    public function reservedLastNumber(): int
    {
        if ($this->status === SsccNumberRangeStatus::Inactive) {
            $start = (int) $this->start_number;
            $current = (int) $this->current_number;
            $increment = max(1, (int) $this->increment_by);

            if ($current <= $start) {
                return $start - 1;
            }

            return $current - $increment;
        }

        return $this->lastIssuableNumber();
    }

    public function recomputeRemaining(): int
    {
        return $this->issuableCount();
    }

    public function utilizationPercentage(): float
    {
        if ($this->range_size <= 0) {
            return 0.0;
        }

        return min(100.0, ($this->utilizedSteps() / $this->range_size) * 100);
    }

    /**
     * Capacity-based utilization for threshold alerting: how much of the band is actually
     * unavailable to issue (pointer advance + labels already consumed off the current cursor),
     * instead of just how far the pointer has walked. A range whose remaining capacity has
     * been eaten by externally-created labels will alert here even if the pointer barely moved.
     */
    public function alertUtilizationPercentage(): float
    {
        if ($this->range_size <= 0) {
            return 0.0;
        }

        return min(100.0, max(0.0, (1 - ($this->issuableCount() / $this->range_size)) * 100));
    }

    public function syncRemainingAndStatus(): void
    {
        $this->remaining = $this->recomputeRemaining();

        if ($this->status === SsccNumberRangeStatus::Inactive) {
            return;
        }

        if ($this->remaining === 0) {
            $this->status = SsccNumberRangeStatus::Depleted;
        } elseif ($this->status === SsccNumberRangeStatus::Depleted) {
            $this->status = SsccNumberRangeStatus::Active;
        }
    }

    public function markInactive(): void
    {
        $this->status = SsccNumberRangeStatus::Inactive;
        $this->syncRemainingAndStatus();
        $this->save();
    }

    public function isSelectable(): bool
    {
        return $this->status === SsccNumberRangeStatus::Active && $this->remaining > 0;
    }

    public function hasIssuedSerials(): bool
    {
        return (int) $this->current_number > (int) $this->start_number;
    }

    public function ownerLabel(): string
    {
        return match ($this->scope) {
            SsccNumberRangeScope::Tenant => 'Tenant',
            SsccNumberRangeScope::Site => $this->site?->name ?? 'Site',
            SsccNumberRangeScope::Partner => $this->tradingPartner?->name ?? 'Partner',
        };
    }

    public function lastIssuableNumber(): int
    {
        return (int) ($this->start_number + (($this->range_size - 1) * $this->increment_by));
    }

    public function endNumberExclusive(): int
    {
        return (int) ($this->start_number + ($this->range_size * $this->increment_by));
    }

    public static function bandIntersects(int $startA, int $endInclusiveA, int $startB, int $endInclusiveB): bool
    {
        return $startA <= $endInclusiveB && $startB <= $endInclusiveA;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveSelectable(Builder $query): Builder
    {
        return $query
            ->where('status', SsccNumberRangeStatus::Active)
            ->where('remaining', '>', 0);
    }
}

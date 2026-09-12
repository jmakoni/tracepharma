<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationRequestCaseStatus;
use App\Enums\VerificationRequestTrigger;
use App\Models\Exceptions\ExceptionCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VerificationRequestCase extends Model
{
    protected $fillable = [
        'verification_id',
        'exception_id',
        'manufacturer_trading_partner_id',
        'requestor_name',
        'requestor_gln',
        'requestor_license',
        'requestor_notify_email',
        'vendor_number',
        'gtin14',
        'serial',
        'lot',
        'expiry_yymmdd',
        'ndc11',
        'product_description',
        'cin',
        'trigger_reason',
        'notes',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => VerificationRequestCaseStatus::class,
            'trigger_reason' => VerificationRequestTrigger::class,
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class, 'exception_id');
    }

    public function manufacturerTradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class, 'manufacturer_trading_partner_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function response(): HasOne
    {
        return $this->hasOne(VerificationRequestResponse::class);
    }

    public function isPending(): bool
    {
        return $this->status === VerificationRequestCaseStatus::Pending
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast()
            && $this->status === VerificationRequestCaseStatus::Pending;
    }
}

<?php

namespace App\Models;

use App\Enums\Fda3911Classification;
use App\Enums\Fda3911ReportStatus;
use App\Models\Exceptions\ExceptionCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Fda3911Report extends Model
{
    use LogsActivity;

    protected $table = 'fda_3911_reports';

    protected $fillable = [
        'status',
        'classification',
        'verification_id',
        'exception_id',
        'trading_partner_id',
        'incident_number',
        'determined_at',
        'submitted_at',
        'acknowledged_at',
        'due_at',
        'notifier_name',
        'notifier_title',
        'notifier_phone',
        'notifier_email',
        'facility_name',
        'facility_gln',
        'facility_address',
        'product_ndc',
        'product_name',
        'product_gtin',
        'lot',
        'serial',
        'strength',
        'dosage_form',
        'circumstances',
        'trading_partner_notifications',
        'generated_pdf_path',
        'metadata',
        'created_by',
        'submitted_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => Fda3911ReportStatus::class,
            'classification' => Fda3911Classification::class,
            'determined_at' => 'datetime',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'due_at' => 'datetime',
            'trading_partner_notifications' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'classification', 'incident_number', 'submitted_at', 'acknowledged_at', 'due_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class);
    }

    public function exceptionCase(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class, 'exception_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! in_array($this->status, [
                Fda3911ReportStatus::Submitted,
                Fda3911ReportStatus::Acknowledged,
                Fda3911ReportStatus::Terminated,
            ], true);
    }
}

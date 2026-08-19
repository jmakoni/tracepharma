<?php

namespace App\Models;

use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Enums\TracingRequestStatus;
use App\Models\Exceptions\ExceptionCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TracingRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'title',
        'status',
        'requestor_type',
        'requested_by',
        'exception_id',
        'gtin',
        'serial',
        'lot',
        'expiry',
        'scope',
        'is_recall',
        'notes',
        'requested_at',
        'due_at',
        'responded_at',
        'completed_at',
        'sla_breached',
        'response_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => TracingRequestStatus::class,
            'requestor_type' => TracingRequestorType::class,
            'scope' => TracingRequestScope::class,
            'is_recall' => 'boolean',
            'expiry' => 'date',
            'requested_at' => 'datetime',
            'due_at' => 'datetime',
            'responded_at' => 'datetime',
            'completed_at' => 'datetime',
            'sla_breached' => 'boolean',
            'response_metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'requestor_type', 'due_at', 'responded_at', 'sla_breached', 'completed_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function exceptionCase(): BelongsTo
    {
        return $this->belongsTo(ExceptionCase::class, 'exception_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(TracingRequestNotification::class);
    }

    public function isOverdue(): bool
    {
        if ($this->responded_at !== null || $this->due_at === null) {
            return false;
        }

        if (in_array($this->status, [TracingRequestStatus::Completed, TracingRequestStatus::Cancelled], true)) {
            return false;
        }

        return $this->due_at->isPast();
    }
}

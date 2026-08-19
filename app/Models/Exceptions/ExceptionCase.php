<?php

namespace App\Models\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionDisposition;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EpcisException;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ExceptionCase extends Model
{
    use LogsActivity;

    protected $table = 'exceptions';

    protected $fillable = [
        'exception_type_id',
        'document_id',
        'event_id',
        'trading_partner_id',
        'site_id',
        'compensating_document_id',
        'title',
        'description',
        'severity',
        'status',
        'assigned_to',
        'assigned_at',
        'due_at',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'root_cause_id',
        'resolution_action_id',
        'resolution_notes',
        'serials_affected',
        'share_uuid',
        'share_expires_at',
        'disposition',
    ];

    protected function casts(): array
    {
        return [
            'severity' => ExceptionSeverity::class,
            'status' => ExceptionStatus::class,
            'disposition' => ExceptionDisposition::class,
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'share_expires_at' => 'datetime',
            'serials_affected' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'severity', 'assigned_to', 'resolved_at', 'closed_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ExceptionType::class, 'exception_type_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'event_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class, 'trading_partner_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function compensatingDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'compensating_document_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function rootCause(): BelongsTo
    {
        return $this->belongsTo(ExceptionRootCause::class, 'root_cause_id');
    }

    public function resolutionAction(): BelongsTo
    {
        return $this->belongsTo(ExceptionAction::class, 'resolution_action_id');
    }

    public function epcs(): BelongsToMany
    {
        return $this->belongsToMany(Epc::class, 'exception_epcs', 'exception_id', 'epc_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ExceptionActivity::class, 'exception_id')->orderByDesc('created_at');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(EpcisException::class, 'case_id');
    }

    public function quarantineHolds(): HasMany
    {
        return $this->hasMany(QuarantineHold::class, 'exception_id');
    }

    /**
     * Document-wide case: tied to a file with no unit-level EPCs/holds (e.g. missing TS).
     */
    public function isDocumentScoped(): bool
    {
        if ($this->document_id === null) {
            return false;
        }

        if ($this->relationLoaded('epcs') && $this->relationLoaded('quarantineHolds')) {
            return $this->epcs->isEmpty() && $this->quarantineHolds->isEmpty();
        }

        return ! $this->epcs()->exists() && ! $this->quarantineHolds()->exists();
    }

    /**
     * Open holds block Resolve/Close unless disposition is already Illegitimate
     * (holds intentionally remain open for physical segregation / FDA follow-up).
     *
     * A hold's `exception_id` only names the case that first opened it, but a unit
     * can be flagged by more than one case via the `exception_epcs` pivot. Checking
     * both keeps case B blocked from closing while a hold "owned" by case A is still
     * open on an EPC that case B also references.
     */
    public function hasBlockingOpenQuarantineHolds(): bool
    {
        if ($this->disposition === ExceptionDisposition::Illegitimate) {
            return false;
        }

        $epcIds = $this->epcs()->pluck('epcs.id')->all();

        return QuarantineHold::query()
            ->open()
            ->where(function (Builder $query) use ($epcIds): void {
                $query->where('exception_id', $this->getKey());

                if ($epcIds !== []) {
                    $query->orWhereIn('epc_id', $epcIds);
                }
            })
            ->exists();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithOpenQuarantine(Builder $query): Builder
    {
        return $query->whereHas('quarantineHolds', fn (Builder $q): Builder => $q->where('status', 'open'));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            ExceptionStatus::Resolved->value,
            ExceptionStatus::Closed->value,
            ExceptionStatus::Cancelled->value,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where('assigned_to', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', ExceptionSeverity::Critical->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWaitingPartner(Builder $query): Builder
    {
        return $query->where('status', ExceptionStatus::WaitingPartner->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeResolvedRecently(Builder $query, int $days = 7): Builder
    {
        return $query->where('status', ExceptionStatus::Resolved->value)
            ->where('resolved_at', '>=', now()->subDays($days));
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function logActivity(
        ExceptionActivityKind $kind,
        ?User $actor = null,
        ?string $body = null,
        ExceptionActivityVisibility $visibility = ExceptionActivityVisibility::Internal,
        ?array $meta = null,
    ): ExceptionActivity {
        return $this->activities()->create([
            'user_id' => $actor?->getKey(),
            'kind' => $kind,
            'visibility' => $visibility,
            'body' => $body,
            'meta' => $meta,
        ]);
    }

    public function caseReference(): string
    {
        return 'EX-'.$this->getKey();
    }
}

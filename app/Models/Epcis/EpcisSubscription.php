<?php

declare(strict_types=1);

namespace App\Models\Epcis;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class EpcisSubscription extends Model
{
    use LogsActivity;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_BOTH = 'both';

    public const FORMAT_JSONLD_20 = 'jsonld_20';

    protected $table = 'epcis_subscriptions';

    protected $fillable = [
        'name',
        'subscription_uuid',
        'target_url',
        'secret',
        'is_active',
        'directions',
        'biz_step_filter',
        'format',
        'query_name',
        'schedule',
        'query_params',
        'created_by',
        'last_delivered_at',
        'last_error_at',
        'last_error',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'biz_step_filter' => 'array',
            'query_params' => 'array',
            'last_delivered_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['secret', 'last_error'])
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::creating(function (EpcisSubscription $subscription): void {
            if (blank($subscription->secret)) {
                $subscription->secret = Str::random(48);
            }

            if (blank($subscription->format)) {
                $subscription->format = self::FORMAT_JSONLD_20;
            }

            if (blank($subscription->subscription_uuid)) {
                $subscription->subscription_uuid = (string) Str::uuid();
            }

            if (blank($subscription->query_name)) {
                $subscription->query_name = 'SimpleEventQuery';
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rotateSecret(): string
    {
        $secret = Str::random(48);
        $this->forceFill(['secret' => $secret])->save();

        return $secret;
    }

    public function matchesDirection(string $direction): bool
    {
        if ($this->directions === self::DIRECTION_BOTH) {
            return true;
        }

        return $this->directions === $direction;
    }

    /**
     * @param  list<string>  $bizSteps
     */
    public function matchesBizSteps(array $bizSteps): bool
    {
        $filter = $this->biz_step_filter;
        if (! is_array($filter) || $filter === []) {
            return true;
        }

        $normalizedFilter = array_map('strval', $filter);

        foreach ($bizSteps as $step) {
            if (in_array((string) $step, $normalizedFilter, true)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Models;

use App\Enums\TracingRequestNotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TracingRequestNotification extends Model
{
    protected $fillable = [
        'tracing_request_id',
        'trading_partner_id',
        'channel',
        'status',
        'sent_at',
        'acknowledged_at',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => TracingRequestNotificationStatus::class,
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tracingRequest(): BelongsTo
    {
        return $this->belongsTo(TracingRequest::class);
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }
}

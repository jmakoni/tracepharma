<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundConnectionLog extends Model
{
    protected $fillable = [
        'inbound_connection_id',
        'event_type',
        'status',
        'message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function inboundConnection(): BelongsTo
    {
        return $this->belongsTo(InboundConnection::class);
    }
}

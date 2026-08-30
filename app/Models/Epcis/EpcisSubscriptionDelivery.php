<?php

declare(strict_types=1);

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisSubscriptionDelivery extends Model
{
    public $timestamps = false;

    protected $table = 'epcis_subscription_deliveries';

    protected $fillable = [
        'subscription_id',
        'document_id',
        'trigger',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(EpcisSubscription::class, 'subscription_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }
}

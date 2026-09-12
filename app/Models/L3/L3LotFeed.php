<?php

declare(strict_types=1);

namespace App\Models\L3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per Guardian lot-close `DataFeed` POST: raw XML archive + ingest status.
 *
 * Status flow: received -> processing -> accepted / failed.
 */
class L3LotFeed extends Model
{
    protected $table = 'l3_lot_feeds';

    protected $fillable = [
        'message_id',
        'file_sha256',
        'payload_disk',
        'payload_path',
        'status',
        'error_summary',
    ];

    public function lots(): HasMany
    {
        return $this->hasMany(SerializationLot::class, 'feed_id');
    }

    /**
     * Only `accepted` is terminal for conversion — `failed` feeds are re-dispatchable
     * on Guardian resubmission and must not no-op when the job runs again.
     */
    public function isTerminal(): bool
    {
        return $this->status === 'accepted';
    }
}

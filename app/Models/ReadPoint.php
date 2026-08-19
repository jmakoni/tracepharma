<?php

namespace App\Models;

use Database\Factories\ReadPointFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadPoint extends Model
{
    /** @use HasFactory<ReadPointFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'code',
        'sgln',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saving(function (ReadPoint $readPoint): void {
            if (blank($readPoint->site_id)) {
                throw new DomainException('A read point must belong to a tenant site.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}

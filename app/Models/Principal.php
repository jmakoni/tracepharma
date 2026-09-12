<?php

namespace App\Models;

use App\Models\Epcis\Epc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Principal extends Model
{
    protected $fillable = [
        'name',
        'gln',
        'external_ref',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * @return HasMany<Epc, $this>
     */
    public function epcs(): HasMany
    {
        return $this->hasMany(Epc::class);
    }
}

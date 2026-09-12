<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyingGroupPartnerFact extends Model
{
    protected $fillable = [
        'member_tenant_id',
        'partner_key',
        'partner_name',
        'partner_gln',
        'license_status',
        'expires_at',
        'as_of',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'expires_at' => 'date',
        ];
    }
}

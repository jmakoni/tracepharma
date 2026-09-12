<?php

namespace App\Models;

use App\Enums\BuyingGroupMemberStatus;
use Illuminate\Database\Eloquent\Model;

class BuyingGroupMember extends Model
{
    protected $fillable = [
        'name',
        'external_ref',
        'member_tenant_id',
        'status',
        'contact_email',
        'dea_number',
        'npi',
        'state_license_ref',
        'primary_gln',
        'affiliation_code',
        'program_sku',
        'notes',
        'sites_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => BuyingGroupMemberStatus::class,
            'sites_count' => 'integer',
        ];
    }
}

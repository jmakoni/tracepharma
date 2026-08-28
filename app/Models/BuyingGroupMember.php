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
    ];

    protected function casts(): array
    {
        return [
            'status' => BuyingGroupMemberStatus::class,
        ];
    }
}

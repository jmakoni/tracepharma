<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyingGroupMemberMetric extends Model
{
    protected $fillable = [
        'member_tenant_id',
        'as_of',
        'atp_gap_count',
        'exceptions_open',
        'exceptions_aging_7d',
        'connection_unhealthy',
        'last_epcis_success_at',
        'health_score',
        'risk_score',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'atp_gap_count' => 'integer',
            'exceptions_open' => 'integer',
            'exceptions_aging_7d' => 'integer',
            'connection_unhealthy' => 'boolean',
            'last_epcis_success_at' => 'datetime',
            'health_score' => 'float',
            'risk_score' => 'float',
        ];
    }
}

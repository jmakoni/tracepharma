<?php

namespace App\Models;

use App\Enums\SsccAllocationMode;
use Illuminate\Database\Eloquent\Model;

class SsccSerialPool extends Model
{
    protected $fillable = [
        'company_prefix',
        'extension_digit',
        'default_allocation_mode',
        'last_serial_reference_int',
        'last_printed_serial_reference_int',
        'last_printed_at',
    ];

    protected function casts(): array
    {
        return [
            'default_allocation_mode' => SsccAllocationMode::class,
            'last_serial_reference_int' => 'integer',
            'last_printed_serial_reference_int' => 'integer',
            'last_printed_at' => 'datetime',
        ];
    }
}

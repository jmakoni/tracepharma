<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoRequest extends Model
{
    protected $fillable = [
        'name',
        'email',
        'company',
        'phone',
        'role',
        'organization_type',
        'message',
        'source',
        'ip_address',
        'user_agent',
    ];
}

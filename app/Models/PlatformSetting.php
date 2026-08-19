<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class PlatformSetting extends Model
{
    use CentralConnection;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
    ];
}

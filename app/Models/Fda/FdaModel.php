<?php

namespace App\Models\Fda;

use App\Models\Fda\Concerns\ProtectsManualFdaEdits;
use Illuminate\Database\Eloquent\Model;

/**
 * Central-connection FDA registry models.
 */
abstract class FdaModel extends Model
{
    use ProtectsManualFdaEdits;

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection', config('database.default'));
    }
}

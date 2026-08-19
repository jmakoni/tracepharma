<?php

use App\Actions\MasterData\PromoteUnassignedSitesToOwned;
use Illuminate\Database\Migrations\Migration;

/**
 * Historical: nulled Unassigned partner sites (superseded — see is_organization_facility).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PromoteUnassignedSitesToOwned::class)->handle();
    }

    public function down(): void
    {
        // Irreversible data cleanup; do not recreate Unassigned partner.
    }
};

<?php

use App\Actions\MasterData\BackfillTenantFdaStampsFromCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BackfillTenantFdaStampsFromCatalog::class)->handle();
    }

    public function down(): void
    {
        // Catalog stamp columns are not restored. Central catalog_* tables remain.
    }
};

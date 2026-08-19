<?php

use App\Actions\Fda\BackfillFdaFromCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (class_exists(BackfillFdaFromCatalog::class)) {
            app(BackfillFdaFromCatalog::class)->handle();
        }
    }

    public function down(): void
    {
        // Data-only backfill; columns remain until the enrich migrations roll back.
    }
};

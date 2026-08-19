<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fda_wdd_3pl_staging', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_wdd_3pl_staging', 'contact_phone')) {
                $table->string('contact_phone', 50)->nullable()->after('contact_email');
            }
        });

        Schema::table('fda_wdd_facilities', function (Blueprint $table): void {
            if (! Schema::hasColumn('fda_wdd_facilities', 'contact_phone')) {
                $table->string('contact_phone', 50)->nullable()->after('contact_email');
            }
        });
    }

    public function down(): void
    {
        foreach (['fda_wdd_3pl_staging', 'fda_wdd_facilities'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (Schema::hasColumn($name, 'contact_phone')) {
                    $table->dropColumn('contact_phone');
                }
            });
        }
    }
};

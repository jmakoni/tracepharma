<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exceptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('exceptions', 'condition_still_true')) {
                $table->boolean('condition_still_true')->nullable()->after('due_at');
            }
            if (! Schema::hasColumn('exceptions', 'sla_stopped_at')) {
                $table->timestamp('sla_stopped_at')->nullable()->after('condition_still_true');
            }
        });
    }

    public function down(): void
    {
        Schema::table('exceptions', function (Blueprint $table): void {
            if (Schema::hasColumn('exceptions', 'sla_stopped_at')) {
                $table->dropColumn('sla_stopped_at');
            }
            if (Schema::hasColumn('exceptions', 'condition_still_true')) {
                $table->dropColumn('condition_still_true');
            }
        });
    }
};

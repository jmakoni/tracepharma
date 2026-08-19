<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exception_types')) {
            return;
        }

        Schema::table('exception_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('exception_types', 'receive_impact')) {
                $table->string('receive_impact', 32)
                    ->nullable()
                    ->after('default_severity');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('exception_types')) {
            return;
        }

        Schema::table('exception_types', function (Blueprint $table): void {
            if (Schema::hasColumn('exception_types', 'receive_impact')) {
                $table->dropColumn('receive_impact');
            }
        });
    }
};

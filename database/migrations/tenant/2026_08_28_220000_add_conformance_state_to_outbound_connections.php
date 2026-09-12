<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_connections', function (Blueprint $table): void {
            $table->string('conformance_state')->default('test')->after('is_default');
            $table->index('conformance_state');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_connections', function (Blueprint $table): void {
            $table->dropIndex(['conformance_state']);
            $table->dropColumn('conformance_state');
        });
    }
};

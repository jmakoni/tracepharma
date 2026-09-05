<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
            $table->boolean('split_declared')->default(false)->after('expected_count');
            $table->timestamp('split_declared_at')->nullable()->after('split_declared');
            $table->foreignId('split_declared_by')->nullable()->after('split_declared_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('split_declared_by');
            $table->dropColumn(['split_declared', 'split_declared_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
            $table->boolean('quantity_gate_overridden')->default(false)->after('split_declared_by');
            $table->timestamp('quantity_gate_overridden_at')->nullable()->after('quantity_gate_overridden');
            $table->text('quantity_gate_override_reason')->nullable()->after('quantity_gate_overridden_at');
            $table->foreignId('quantity_gate_overridden_by')->nullable()->after('quantity_gate_override_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outbound_shipping_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quantity_gate_overridden_by');
            $table->dropColumn([
                'quantity_gate_overridden',
                'quantity_gate_overridden_at',
                'quantity_gate_override_reason',
            ]);
        });
    }
};

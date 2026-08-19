<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_epc_ilmd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('epcis_events')->cascadeOnDelete();
            $table->foreignId('epc_id')->constrained('epcs')->cascadeOnDelete();
            $table->string('lot_number', 20)->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('best_before_date')->nullable();
            $table->string('additional_id', 64)->nullable();
            $table->json('extra_json')->nullable();

            $table->unique(['event_id', 'epc_id']);
            $table->index('epc_id');
        });

        Schema::create('event_quantities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('epcis_events')->cascadeOnDelete();
            $table->string('role', 32);
            $table->string('epc_class', 191);
            $table->decimal('quantity', 14, 4)->nullable();
            $table->string('uom', 32)->nullable();

            $table->index(['event_id', 'role']);
        });

        if (Schema::hasTable('event_biz_transactions') && Schema::hasColumn('event_biz_transactions', 'value')) {
            if ($this->indexExists('event_biz_transactions', 'event_biz_transactions_value_index')) {
                Schema::table('event_biz_transactions', function (Blueprint $table) {
                    $table->dropIndex('event_biz_transactions_value_index');
                });
            }

            DB::statement('ALTER TABLE event_biz_transactions MODIFY value TEXT NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_biz_transactions') && Schema::hasColumn('event_biz_transactions', 'value')) {
            DB::statement('ALTER TABLE event_biz_transactions MODIFY value VARCHAR(128) NOT NULL');

            if (! $this->indexExists('event_biz_transactions', 'event_biz_transactions_value_index')) {
                Schema::table('event_biz_transactions', function (Blueprint $table) {
                    $table->index('value');
                });
            }
        }

        Schema::dropIfExists('event_quantities');
        Schema::dropIfExists('event_epc_ilmd');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$indexName]))
            ->isNotEmpty();
    }
};

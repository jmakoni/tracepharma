<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('outbound_connection_trading_partner');

        Schema::create('outbound_connection_trading_partner', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outbound_connection_id');
            $table->unsignedBigInteger('trading_partner_id');
            $table->timestamps();

            $table->foreign('outbound_connection_id', 'octp_connection_fk')
                ->references('id')
                ->on('outbound_connections')
                ->cascadeOnDelete();
            $table->foreign('trading_partner_id', 'octp_partner_fk')
                ->references('id')
                ->on('trading_partners')
                ->cascadeOnDelete();

            $table->unique(
                ['outbound_connection_id', 'trading_partner_id'],
                'octp_connection_partner_unique',
            );
            $table->index('trading_partner_id', 'octp_partner_index');
        });

        if (! Schema::hasTable('outbound_connections')) {
            return;
        }

        $now = now();

        DB::table('outbound_connections')
            ->whereNotNull('trading_partner_id')
            ->orderBy('id')
            ->select(['id', 'trading_partner_id'])
            ->chunkById(100, function ($rows) use ($now): void {
                $inserts = [];

                foreach ($rows as $row) {
                    $inserts[] = [
                        'outbound_connection_id' => (int) $row->id,
                        'trading_partner_id' => (int) $row->trading_partner_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($inserts !== []) {
                    DB::table('outbound_connection_trading_partner')->insertOrIgnore($inserts);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_connection_trading_partner');
    }
};

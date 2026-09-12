<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'hrgcr_tenant_provider_gln_status_unique';

    private const NEW_UNIQUE = 'hrgcr_tenant_provider_gln_unique';

    public function up(): void
    {
        DB::table('hub_receiver_gln_claim_requests')
            ->select(['tenant_id', 'provider', 'gln'])
            ->groupBy('tenant_id', 'provider', 'gln')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function (object $identity): void {
                $ids = DB::table('hub_receiver_gln_claim_requests')
                    ->where('tenant_id', $identity->tenant_id)
                    ->where('provider', $identity->provider)
                    ->where('gln', $identity->gln)
                    ->orderByDesc('id')
                    ->pluck('id');

                DB::table('hub_receiver_gln_claim_requests')
                    ->whereIn('id', $ids->slice(1))
                    ->delete();
            });

        Schema::table('hub_receiver_gln_claim_requests', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'provider', 'gln'], self::NEW_UNIQUE);
        });

        Schema::table('hub_receiver_gln_claim_requests', function (Blueprint $table): void {
            $table->dropUnique(self::OLD_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('hub_receiver_gln_claim_requests', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'provider', 'gln', 'status'],
                self::OLD_UNIQUE,
            );
        });

        Schema::table('hub_receiver_gln_claim_requests', function (Blueprint $table): void {
            $table->dropUnique(self::NEW_UNIQUE);
        });
    }
};

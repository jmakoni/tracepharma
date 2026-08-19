<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateIds = DB::table('customer_onboardings')
            ->select('tenant_id')
            ->whereNotNull('tenant_id')
            ->groupBy('tenant_id')
            ->havingRaw('count(*) > 1')
            ->pluck('tenant_id');

        foreach ($duplicateIds as $tenantId) {
            $keepId = DB::table('customer_onboardings')
                ->where('tenant_id', $tenantId)
                ->orderByRaw("case when status = 'rejected' then 1 else 0 end")
                ->orderBy('id')
                ->value('id');

            DB::table('customer_onboardings')
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $keepId)
                ->update(['tenant_id' => null]);
        }

        if (Schema::hasIndex('customer_onboardings', 'customer_onboardings_tenant_id_index')) {
            Schema::table('customer_onboardings', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
            });
        }

        if (! Schema::hasIndex('customer_onboardings', 'customer_onboardings_tenant_id_unique')) {
            Schema::table('customer_onboardings', function (Blueprint $table): void {
                $table->unique('tenant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('customer_onboardings', 'customer_onboardings_tenant_id_unique')) {
            Schema::table('customer_onboardings', function (Blueprint $table): void {
                $table->dropUnique(['tenant_id']);
            });
        }

        if (! Schema::hasIndex('customer_onboardings', 'customer_onboardings_tenant_id_index')) {
            Schema::table('customer_onboardings', function (Blueprint $table): void {
                $table->index('tenant_id');
            });
        }
    }
};

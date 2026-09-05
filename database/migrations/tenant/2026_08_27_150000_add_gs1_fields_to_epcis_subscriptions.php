<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_subscriptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('epcis_subscriptions', 'subscription_uuid')) {
                $table->uuid('subscription_uuid')->nullable()->after('id');
            }
            if (! Schema::hasColumn('epcis_subscriptions', 'query_name')) {
                $table->string('query_name', 64)->default('SimpleEventQuery')->after('format');
            }
            if (! Schema::hasColumn('epcis_subscriptions', 'schedule')) {
                $table->string('schedule', 128)->nullable()->after('query_name');
            }
            if (! Schema::hasColumn('epcis_subscriptions', 'query_params')) {
                $table->json('query_params')->nullable()->after('schedule');
            }
        });

        DB::table('epcis_subscriptions')
            ->whereNull('subscription_uuid')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('epcis_subscriptions')
                        ->where('id', $row->id)
                        ->update(['subscription_uuid' => (string) Str::uuid()]);
                }
            });

        Schema::table('epcis_subscriptions', function (Blueprint $table): void {
            $table->unique('subscription_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_subscriptions', function (Blueprint $table): void {
            $table->dropUnique(['subscription_uuid']);
            $table->dropColumn(['subscription_uuid', 'query_name', 'schedule', 'query_params']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_trading_partners', 'atp_license_number')) {
            return;
        }

        $partners = DB::table('catalog_trading_partners')
            ->whereNotNull('atp_license_number')
            ->where('atp_license_number', '!=', '')
            ->get(['id', 'atp_license_number', 'atp_license_state', 'atp_expires_on']);

        foreach ($partners as $partner) {
            $siteId = DB::table('catalog_sites')
                ->where('catalog_trading_partner_id', $partner->id)
                ->orderByDesc('is_headquarters')
                ->value('id');

            if (! $siteId || ! $partner->atp_license_state) {
                continue;
            }

            $exists = DB::table('catalog_atp_licenses')
                ->where('license_state', $partner->atp_license_state)
                ->where('license_number', $partner->atp_license_number)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('catalog_atp_licenses')->insert([
                'catalog_site_id' => $siteId,
                'facility_type' => 'wdd',
                'license_number' => $partner->atp_license_number,
                'license_state' => $partner->atp_license_state,
                'license_expiration_date' => $partner->atp_expires_on,
                'reporting_year' => (int) date('Y'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            $table->dropColumn(['atp_license_number', 'atp_license_state', 'atp_expires_on']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_trading_partners', function (Blueprint $table) {
            $table->string('atp_license_number')->nullable();
            $table->string('atp_license_state', 2)->nullable();
            $table->date('atp_expires_on')->nullable();
        });
    }
};

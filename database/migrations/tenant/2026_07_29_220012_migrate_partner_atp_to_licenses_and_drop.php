<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trading_partners', 'atp_license_number')) {
            return;
        }

        $partners = DB::table('trading_partners')
            ->whereNotNull('atp_license_number')
            ->where('atp_license_number', '!=', '')
            ->get();

        foreach ($partners as $partner) {
            $siteId = null;

            if (Schema::hasColumn('sites', 'trading_partner_id')) {
                $siteId = DB::table('sites')
                    ->where('trading_partner_id', $partner->id)
                    ->where('is_headquarters', true)
                    ->value('id');

                if (! $siteId) {
                    $siteId = DB::table('sites')
                        ->where('trading_partner_id', $partner->id)
                        ->value('id');
                }
            }

            if (! $siteId) {
                $siteId = DB::table('sites')->value('id');
            }

            $state = $partner->atp_license_state ?: 'XX';

            $exists = DB::table('atp_licenses')
                ->where('license_state', $state)
                ->where('license_number', $partner->atp_license_number)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('atp_licenses')->insert([
                'site_id' => $siteId,
                'facility_type' => 'wdd',
                'license_number' => $partner->atp_license_number,
                'license_state' => $state,
                'license_expiration_date' => $partner->atp_expires_on ?? null,
                'reporting_year' => (int) now()->year,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('trading_partners', function (Blueprint $table) {
            $table->dropColumn(['atp_license_number', 'atp_license_state', 'atp_expires_on']);
        });
    }

    public function down(): void
    {
        Schema::table('trading_partners', function (Blueprint $table) {
            $table->string('atp_license_number')->nullable();
            $table->string('atp_license_state', 2)->nullable();
            $table->date('atp_expires_on')->nullable();
        });
    }
};

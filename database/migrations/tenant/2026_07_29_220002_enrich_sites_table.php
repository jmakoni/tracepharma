<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('trading_partner_id')
                ->nullable()
                ->after('id')
                ->constrained('trading_partners')
                ->nullOnDelete();
            $table->boolean('is_headquarters')->default(false)->after('name');
            $table->text('description')->nullable()->after('is_headquarters');
            $table->decimal('altitude', 8, 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('logo', 2048)->nullable();
        });

        if (Schema::hasColumn('sites', 'address_line1')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->renameColumn('address_line1', 'street_address');
            });
        }
        if (Schema::hasColumn('sites', 'address_line2')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->renameColumn('address_line2', 'street_address_2');
            });
        }
        if (Schema::hasColumn('sites', 'postal_code')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->renameColumn('postal_code', 'zipcode');
            });
        }
        if (Schema::hasColumn('sites', 'country')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->renameColumn('country', 'country_code');
            });
        }

        DB::table('sites')->whereNull('country_code')->orWhere('country_code', '')->update(['country_code' => 'US']);
        DB::statement("ALTER TABLE sites MODIFY country_code VARCHAR(3) NOT NULL DEFAULT 'US'");

        $unassignedId = DB::table('trading_partners')->where('name', 'Unassigned')->value('id');
        if (! $unassignedId) {
            $unassignedId = DB::table('trading_partners')->insertGetId([
                'name' => 'Unassigned',
                'partner_type' => 'other',
                'country_code' => 'US',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('sites')->whereNull('trading_partner_id')->update(['trading_partner_id' => $unassignedId]);

        $glnIndexes = collect(DB::select("SHOW INDEX FROM sites WHERE Column_name = 'gln'"));
        if ($glnIndexes->isEmpty()) {
            Schema::table('sites', function (Blueprint $table) {
                $table->unique('gln');
            });
        }

        if (! Schema::hasColumn('sites', 'sgln')) {
            DB::statement("ALTER TABLE sites ADD COLUMN sgln VARCHAR(50) GENERATED ALWAYS AS (IF(gln IS NULL OR CHAR_LENGTH(gln) < 12, NULL, CONCAT('urn:epc:id:sgln:', LEFT(gln, 12), '.0'))) STORED");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sites', 'sgln')) {
            DB::statement('ALTER TABLE sites DROP COLUMN sgln');
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trading_partner_id');
            $table->dropColumn(['is_headquarters', 'description', 'altitude', 'latitude', 'longitude', 'logo']);
        });
    }
};

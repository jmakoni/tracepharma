<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_partners', function (Blueprint $table) {
            $table->string('doing_business_as')->nullable()->after('name');
            $table->text('description')->nullable()->after('doing_business_as');
            $table->string('street_address')->nullable()->after('partner_type');
            $table->string('street_address_2')->nullable()->after('street_address');
            $table->string('city', 100)->nullable()->after('street_address_2');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('zipcode', 20)->nullable()->after('state');
            $table->string('country_code', 3)->default('US')->after('zipcode');
            $table->decimal('altitude', 8, 2)->nullable()->after('gln');
            $table->decimal('latitude', 10, 7)->nullable()->after('altitude');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('logo', 2048)->nullable()->after('longitude');
            $table->string('website')->nullable()->after('logo');
            $table->string('telephone', 50)->nullable()->after('website');
            $table->string('email')->nullable()->after('telephone');
            $table->string('fax', 50)->nullable()->after('email');
        });

        if (! Schema::hasColumn('trading_partners', 'sgln')) {
            DB::statement("ALTER TABLE trading_partners ADD COLUMN sgln VARCHAR(50) GENERATED ALWAYS AS (IF(gln IS NULL OR CHAR_LENGTH(gln) < 12, NULL, CONCAT('urn:epc:id:sgln:', LEFT(gln, 12), '.0'))) STORED");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('trading_partners', 'sgln')) {
            DB::statement('ALTER TABLE trading_partners DROP COLUMN sgln');
        }

        Schema::table('trading_partners', function (Blueprint $table) {
            $table->dropColumn([
                'doing_business_as', 'description', 'street_address', 'street_address_2',
                'city', 'state', 'zipcode', 'country_code', 'altitude', 'latitude', 'longitude',
                'logo', 'website', 'telephone', 'email', 'fax',
            ]);
        });
    }
};

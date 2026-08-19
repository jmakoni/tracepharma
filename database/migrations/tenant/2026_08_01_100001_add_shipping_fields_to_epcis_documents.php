<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->string('customer_po', 64)->nullable()->after('receiver_gln');
            $table->string('asn_number', 64)->nullable()->after('customer_po');
            $table->char('ship_from_gln', 13)->nullable()->after('asn_number');
            $table->char('ship_to_gln', 13)->nullable()->after('ship_from_gln');
            $table->foreignId('ship_from_site_id')->nullable()->after('ship_to_gln')->constrained('sites')->nullOnDelete();
            $table->foreignId('ship_to_site_id')->nullable()->after('ship_from_site_id')->constrained('sites')->nullOnDelete();
            $table->foreignId('ship_to_partner_id')->nullable()->after('ship_to_site_id')->constrained('trading_partners')->nullOnDelete();

            $table->index('customer_po');
            $table->index('asn_number');
        });
    }

    public function down(): void
    {
        Schema::table('epcis_documents', function (Blueprint $table) {
            $table->dropForeign(['ship_from_site_id']);
            $table->dropForeign(['ship_to_site_id']);
            $table->dropForeign(['ship_to_partner_id']);
            $table->dropIndex(['customer_po']);
            $table->dropIndex(['asn_number']);
            $table->dropColumn([
                'customer_po',
                'asn_number',
                'ship_from_gln',
                'ship_to_gln',
                'ship_from_site_id',
                'ship_to_site_id',
                'ship_to_partner_id',
            ]);
        });
    }
};

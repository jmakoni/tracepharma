<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'catalog_atp_licenses',
        'catalog_location_devices',
        'catalog_products',
        'catalog_sites',
        'catalog_devices',
        'catalog_trading_partners',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Catalog tables are not restored. FDA remains the system of record.
    }
};

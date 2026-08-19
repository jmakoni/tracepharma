<?php

use App\Support\TenantSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The generated `sgln` columns produced `urn:epc:id:sgln:{first 12 digits}.0` — two
 * segments where GS1 Pure Identity requires three. Nothing could parse it, so every
 * location it described read as unidentified, and the shipping authors quietly fell
 * back to splitting partner GLNs on our own company prefix.
 *
 * The column becomes a real one so it can hold what a generated expression never
 * could: the company-prefix split. It is filled here only for GLNs issued under this
 * organization's GS1 Company Prefix — the one case where we know the split. Partner
 * locations are left null until their own SGLN is recorded. The DerivesSgln concern
 * keeps rows written after this migration to the same rule.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['sites', 'trading_partners', 'location_devices'];

    private const LEGACY_EXPRESSION = "IF(gln IS NULL OR CHAR_LENGTH(gln) < 12, NULL, CONCAT('urn:epc:id:sgln:', LEFT(gln, 12), '.0'))";

    public function up(): void
    {
        $prefix = $this->companyPrefix();

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($this->columnIsGenerated($table)) {
                DB::statement("ALTER TABLE `{$table}` DROP COLUMN `sgln`");
            }

            if (! Schema::hasColumn($table, 'sgln')) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `sgln` VARCHAR(64) NULL AFTER `gln`");
            }

            if ($prefix !== null) {
                $this->backfillOrganizationSglns($table, $prefix);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sgln')) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `sgln`");
            DB::statement(
                "ALTER TABLE `{$table}` ADD COLUMN `sgln` VARCHAR(50) GENERATED ALWAYS AS (".self::LEGACY_EXPRESSION.') STORED',
            );
        }
    }

    /**
     * Only a GLN that starts with the organization prefix was issued under it; anything
     * else shares no more with us than 13 digits.
     */
    private function backfillOrganizationSglns(string $table, string $prefix): void
    {
        $length = strlen($prefix);

        DB::update(
            "UPDATE `{$table}` SET `sgln` = CONCAT('urn:epc:id:sgln:', ?, '.', SUBSTRING(`gln`, ?, ?), '.0') "
            ."WHERE `gln` REGEXP '^[0-9]{13}$' AND LEFT(`gln`, ?) = ?",
            [$prefix, $length + 1, 12 - $length, $length, $prefix],
        );
    }

    private function companyPrefix(): ?string
    {
        $tenant = function_exists('tenant') ? tenant() : null;

        $prefix = TenantSettings::normalizeCompanyPrefix(
            is_object($tenant) && isset($tenant->company_prefix) ? (string) $tenant->company_prefix : null,
        );

        if ($prefix === null || strlen($prefix) < 6 || strlen($prefix) > 11) {
            return null;
        }

        return $prefix;
    }

    private function columnIsGenerated(string $table): bool
    {
        $column = DB::selectOne(
            'SELECT EXTRA FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, 'sgln'],
        );

        return $column !== null
            && str_contains(strtoupper((string) (((array) $column)['EXTRA'] ?? '')), 'GENERATED');
    }
};

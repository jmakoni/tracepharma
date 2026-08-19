<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

$connection = Schema::getConnection();
$schema = $connection->getDatabaseName();

$hasTable = static fn (string $table): bool => Schema::hasTable($table);
$hasColumn = static fn (string $table, string $column): bool => Schema::hasColumn($table, $column);

$markRan = static function (string $migration) use ($connection): void {
    $exists = $connection->table('migrations')->where('migration', $migration)->exists();
    if ($exists) {
        return;
    }
    $batch = (int) $connection->table('migrations')->max('batch') + 1;
    $connection->table('migrations')->insert([
        'migration' => $migration,
        'batch' => $batch,
    ]);
    echo "marked {$migration}\n";
};

if ($hasTable('admin_users') && ! $hasTable('admins')) {
    $connection->statement('RENAME TABLE admin_users TO admins');
    echo "renamed admin_users -> admins\n";
}

if ($hasTable('admins') && ! $hasColumn('admins', 'preferences')) {
    Schema::table('admins', function (Blueprint $table): void {
        $table->json('preferences')->nullable();
    });
    echo "added admins.preferences\n";
}

if ($hasTable('tenants')) {
    Schema::table('tenants', function (Blueprint $table) use ($hasColumn): void {
        if (! $hasColumn('tenants', 'company_prefix')) {
            $table->string('company_prefix', 12)->nullable();
        }
        if (! $hasColumn('tenants', 'inbound_environment')) {
            $table->string('inbound_environment')->nullable();
        }
        if (! $hasColumn('tenants', 'hub_providers')) {
            $table->json('hub_providers')->nullable();
        }
    });
    echo "ensured tenant hub columns\n";
}

if ($hasTable('tenant_user_impersonation_tokens')) {
    Schema::table('tenant_user_impersonation_tokens', function (Blueprint $table) use ($hasColumn): void {
        if (! $hasColumn('tenant_user_impersonation_tokens', 'admin_id')) {
            $table->unsignedBigInteger('admin_id')->nullable();
        }
        if (! $hasColumn('tenant_user_impersonation_tokens', 'reason')) {
            $table->text('reason')->nullable();
        }
        if (! $hasColumn('tenant_user_impersonation_tokens', 'admin_ip')) {
            $table->string('admin_ip', 45)->nullable();
        }
    });
    echo "ensured impersonation audit columns\n";
}

$alreadyPresent = [
    '2026_07_29_190335_create_activity_log_table' => $hasTable('activity_log'),
    '2026_07_29_190335_create_permission_tables' => $hasTable('permissions'),
    '2026_07_29_190337_create_personal_access_tokens_table' => $hasTable('personal_access_tokens'),
    '2026_07_29_190338_create_breezy_sessions_table' => $hasTable('breezy_sessions'),
    '2026_07_29_190339_alter_breezy_sessions_table' => $hasTable('breezy_sessions'),
    '2026_07_29_190340_create_passkeys_table' => $hasTable('passkeys'),
    '2026_07_29_191000_add_tenant_profile_columns' => $hasTable('tenants') && $hasColumn('tenants', 'profile'),
    '2026_07_29_191100_create_admins_table' => $hasTable('admins'),
    '2026_07_31_100010_add_avatar_url_to_admins_table' => $hasTable('admins') && $hasColumn('admins', 'avatar_url'),
    '2026_07_31_100011_add_avatar_url_to_users_table' => $hasTable('users') && $hasColumn('users', 'avatar_url'),
    '2026_08_07_120000_add_company_prefix_to_tenants_table' => $hasTable('tenants') && $hasColumn('tenants', 'company_prefix'),
    '2026_08_07_184500_create_epcis_hub_routes_table' => $hasTable('epcis_hub_routes'),
    '2026_08_07_193000_create_platform_settings_table' => $hasTable('platform_settings'),
    '2026_08_07_193100_add_epcis_hub_columns_to_tenants_table' => $hasTable('tenants') && $hasColumn('tenants', 'inbound_environment'),
    '2026_08_12_210000_create_demo_requests_table' => $hasTable('demo_requests'),
    '2026_08_12_210001_create_customer_onboardings_table' => $hasTable('customer_onboardings'),
    '2026_08_16_120000_add_audit_fields_to_tenant_user_impersonation_tokens_table' => $hasTable('tenant_user_impersonation_tokens') && $hasColumn('tenant_user_impersonation_tokens', 'admin_id'),
    '2026_08_16_170000_add_preferences_to_admins_table' => $hasTable('admins') && $hasColumn('admins', 'preferences'),
];

foreach ($alreadyPresent as $migration => $present) {
    if ($present) {
        $markRan($migration);
    }
}

echo "schema={$schema} admins=".($hasTable('admins') ? 'yes' : 'no')
    .' mail_templates='.($hasTable('mail_templates') ? 'yes' : 'no')
    .' customer_onboardings='.($hasTable('customer_onboardings') ? 'yes' : 'no')
    .PHP_EOL;

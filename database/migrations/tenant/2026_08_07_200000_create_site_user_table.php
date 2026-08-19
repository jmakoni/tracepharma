<?php

use App\Enums\TenantRole;
use App\Models\User;
use App\Support\Auth\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_user')) {
            return;
        }

        Schema::create('site_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'site_id']);
            $table->index('site_id');
            $table->index(['user_id', 'is_default']);
        });

        $guard = 'web';
        $permission = Permission::findOrCreate(Permissions::SitesAccessAll, $guard);

        $ownerRole = Role::query()
            ->where('name', TenantRole::Owner->value)
            ->where('guard_name', $guard)
            ->first();

        if ($ownerRole !== null) {
            $ownerRole->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cutoverNonOwnerSiteMembership($ownerRole);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_user');
    }

    private function cutoverNonOwnerSiteMembership(?Role $ownerRole): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasTable('users')) {
            return;
        }

        $orgSiteIds = $this->organizationFacilitySiteIds();

        if ($orgSiteIds === []) {
            return;
        }

        $defaultSiteId = $orgSiteIds[0];
        $ownerUserIds = $this->ownerUserIds($ownerRole);
        $now = now();

        $userIds = DB::table('users')->pluck('id');

        foreach ($userIds as $userId) {
            if (in_array((int) $userId, $ownerUserIds, true)) {
                continue;
            }

            foreach ($orgSiteIds as $siteId) {
                DB::table('site_user')->insertOrIgnore([
                    'user_id' => (int) $userId,
                    'site_id' => $siteId,
                    'is_default' => $siteId === $defaultSiteId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function organizationFacilitySiteIds(): array
    {
        $query = DB::table('sites');

        if (Schema::hasColumn('sites', 'is_organization_facility')) {
            $query->where('is_organization_facility', true);
        } else {
            $query->whereNull('trading_partner_id');
        }

        return $query
            ->orderByDesc('is_headquarters')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function ownerUserIds(?Role $ownerRole): array
    {
        if ($ownerRole === null) {
            return [];
        }

        $tableNames = config('permission.table_names');
        $modelHasRoles = $tableNames['model_has_roles'] ?? null;

        if ($modelHasRoles === null || ! Schema::hasTable($modelHasRoles)) {
            return [];
        }

        return DB::table($modelHasRoles)
            ->where('role_id', $ownerRole->id)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }
};

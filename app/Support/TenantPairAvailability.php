<?php

namespace App\Support;

use App\Actions\Tenants\DeleteTenantPair;
use App\Enums\CustomerOnboardingStatus;
use App\Models\CustomerOnboarding;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stancl\Tenancy\Database\Models\Domain;

class TenantPairAvailability
{
    public static function assertOpenForProvisioning(string $slug, ?string $resumeTenantId = null): void
    {
        $slug = strtolower($slug);
        TenantHostname::assertProvisionableSlug($slug);

        $owners = [];

        foreach (TenantHostname::PAIR_ENVIRONMENTS as $environment) {
            $hostname = TenantHostname::forSlug($slug, $environment);
            $domain = Domain::query()->where('domain', $hostname)->first();

            if ($domain === null) {
                $database = TenantDatabaseName::fromDomain($hostname);

                if (self::schemaExists($database)) {
                    throw new RuntimeException(
                        "A leftover tenant database already exists for {$hostname} ({$database}). Drop that database or choose a different slug."
                    );
                }

                continue;
            }

            $tenant = Tenant::query()->find($domain->tenant_id);

            if (! $tenant instanceof Tenant || ! self::ownsSlug($tenant, $slug)) {
                throw new RuntimeException("The host {$hostname} is already taken.");
            }

            $owners[$environment] = $tenant;
        }

        if ($owners === []) {
            return;
        }

        if (! isset($owners['prod']) && isset($owners['stage'])) {
            throw new RuntimeException(
                'The host '.TenantHostname::forSlug($slug, 'stage').' is already taken without a matching prod host.'
            );
        }

        if (isset($owners['prod'], $owners['stage'])) {
            $prod = $owners['prod'];
            $stage = $owners['stage'];

            if ($resumeTenantId !== null && (string) $prod->id === $resumeTenantId) {
                if (! self::stageIsProdSibling($prod, $stage, $slug)) {
                    throw new RuntimeException(
                        'The host '.TenantHostname::forSlug($slug, 'stage').' is already taken.'
                    );
                }

                return;
            }

            throw new RuntimeException(
                'The host '.TenantHostname::forSlug($slug, 'prod').' is already taken.'
            );
        }

        if (isset($owners['prod'])) {
            $prod = $owners['prod'];

            if ($resumeTenantId !== null && (string) $prod->id === $resumeTenantId) {
                return;
            }

            if (self::prodClaimedByOnboarding((string) $prod->id)) {
                throw new RuntimeException(
                    'The host '.TenantHostname::forSlug($slug, 'prod').' is already linked to another onboarding request. Link this application to that tenant or choose a different slug.'
                );
            }
        }
    }

    public static function assertSlugAllowedFor(CustomerOnboarding $onboarding, string $slug): void
    {
        $slug = strtolower($slug);

        if ($onboarding->tenant_id !== null) {
            $linked = Tenant::query()->find($onboarding->tenant_id);

            if ($linked instanceof Tenant && ! self::ownsSlug($linked, $slug)) {
                throw new RuntimeException('This onboarding is linked to a different tenant.');
            }
        }

        self::assertOpenForProvisioning($slug, self::resumeTenantIdFor($onboarding, $slug));
    }

    public static function resumeTenantIdFor(CustomerOnboarding $onboarding, string $slug): ?string
    {
        $slug = strtolower($slug);

        if ($onboarding->tenant_id !== null) {
            $linked = Tenant::query()->find($onboarding->tenant_id);

            if ($linked instanceof Tenant && self::ownsSlug($linked, $slug)) {
                return (string) $linked->id;
            }

            return null;
        }

        $prodHost = TenantHostname::forSlug($slug, 'prod');
        $domain = Domain::query()->where('domain', $prodHost)->first();

        if ($domain === null) {
            return null;
        }

        $prod = Tenant::query()->find($domain->tenant_id);

        if (! $prod instanceof Tenant || ! self::ownsSlug($prod, $slug)) {
            return null;
        }

        if (self::prodClaimedByOnboarding((string) $prod->id)) {
            return null;
        }

        return (string) $prod->id;
    }

    public static function prodClaimedByOnboarding(string $prodTenantId): bool
    {
        if (! Tenant::query()->whereKey($prodTenantId)->exists()) {
            return false;
        }

        return CustomerOnboarding::query()
            ->where('tenant_id', $prodTenantId)
            ->whereIn('status', CustomerOnboardingStatus::claimingProdTenant())
            ->exists();
    }

    public static function ownsSlug(Tenant $tenant, string $slug): bool
    {
        $slug = strtolower($slug);

        return is_string($tenant->tenant_pair_slug)
            && $tenant->tenant_pair_slug !== ''
            && $tenant->tenant_pair_slug === $slug;
    }

    private static function stageIsProdSibling(Tenant $prod, Tenant $stage, string $slug): bool
    {
        $sibling = app(DeleteTenantPair::class)->sibling($prod);

        if (! $sibling instanceof Tenant || ! $sibling->is($stage)) {
            return false;
        }

        if (! is_string($stage->tenant_pair_slug) || strtolower($stage->tenant_pair_slug) !== $slug) {
            return false;
        }

        return is_string($stage->tenant_pair_environment)
            && $stage->tenant_pair_environment === 'stage';
    }

    public static function validationMessage(string $slug, ?string $resumeTenantId = null): ?string
    {
        try {
            self::assertOpenForProvisioning($slug, $resumeTenantId);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private static function schemaExists(string $database): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return false;
        }

        return DB::selectOne(
            'select schema_name from information_schema.schemata where schema_name = ?',
            [$database],
        ) !== null;
    }

    public static function validationMessageFor(CustomerOnboarding $onboarding, string $slug): ?string
    {
        try {
            self::assertSlugAllowedFor($onboarding, $slug);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}

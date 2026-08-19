<?php

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use App\Support\TenantSettings;
use RuntimeException;
use Throwable;

class ProvisionTenantPair
{
    public function __construct(
        private readonly ProvisionTenantOnEnvironment $onEnvironment,
        private readonly EnsureTenantOwner $ensureOwner,
        private readonly DeleteTenantPair $deletePair,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     profile?: \App\Enums\TenantProfile|string,
     *     status?: string,
     *     gln?: ?string,
     *     company_prefix?: ?string,
     *     receiving_state?: ?string,
     *     hub_providers?: ?array<int, string>
     * }  $attributes
     * @param  array<string, mixed>  $address
     * @param  array{name: string, email: string, password: string}|null  $owner
     */
    public function create(
        string $slug,
        array $attributes,
        array $address = [],
        ?array $owner = null,
        ?string $resumeTenantId = null,
    ): Tenant {
        $slug = strtolower($slug);

        // Idempotent re-create of an existing pair needs the prod tenant as resume id;
        // assertOpenForProvisioning otherwise treats a complete pair as "already taken".
        if ($resumeTenantId === null) {
            $existingProd = $this->onEnvironment->findBySlugAndEnvironment($slug, 'prod');

            if (
                $existingProd instanceof Tenant
                && TenantPairAvailability::ownsSlug($existingProd, $slug)
            ) {
                $resumeTenantId = (string) $existingProd->id;
            }
        }

        if ($resumeTenantId !== null) {
            $this->cleanSquattersOnResume($resumeTenantId);
        }

        TenantPairAvailability::assertOpenForProvisioning($slug, $resumeTenantId);

        $isResume = $resumeTenantId !== null;

        $prod = $this->onEnvironment->provision($slug, $attributes, 'prod', $address);

        try {
            $stage = $this->onEnvironment->provision($slug, $attributes, 'stage', $address);
        } catch (Throwable $exception) {
            if ($isResume) {
                if ($owner !== null) {
                    $this->ensureOwner->handle($prod, $owner);
                }

                TenantSettings::forTenant($prod)->setPairStatus('partial');
                $prod->save();

                $prodHost = TenantHostname::forSlug($slug, 'prod');

                throw new RuntimeException(
                    'Prod tenant exists at '.$prodHost.' (pair_status=partial). Stage failed: '.$exception->getMessage()
                    .'. Re-run: php artisan tracepharma:provision-tenant "'.$attributes['name'].'" '.$slug.' --environment=stage',
                    0,
                    $exception,
                );
            }

            $this->deletePair->deleteWithSibling($prod);

            throw new RuntimeException(
                'Stage provision failed and the pair was rolled back: '.$exception->getMessage()
                .'. Retry full pair provision.',
                0,
                $exception,
            );
        }

        if ($owner !== null) {
            $this->ensureOwner->handle($prod, $owner);
            $this->ensureOwner->handle($stage, $owner);
            app(NotifyTenantProvisioned::class)->handle($prod, $stage, $owner);
        }

        return $prod->load('domains');
    }

    private function cleanSquattersOnResume(string $resumeTenantId): void
    {
        $prod = Tenant::query()->find($resumeTenantId);

        if (! $prod instanceof Tenant) {
            return;
        }

        $preserveTenantIds = [$resumeTenantId];
        $sibling = $this->deletePair->sibling($prod);

        if ($sibling instanceof Tenant) {
            $preserveTenantIds[] = (string) $sibling->id;
        }

        $this->deletePair->cleanPairSquatters($prod, $preserveTenantIds);
    }
}

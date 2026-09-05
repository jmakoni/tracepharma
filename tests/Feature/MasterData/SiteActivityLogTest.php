<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A site is the physical party on every EPCIS event it reads or authors, so an inspector
 * asking "where was this shipped from, and who owned that dock" needs the identity,
 * ownership, activation and address history rather than only the current row.
 */
class SiteActivityLogTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $siteId = null;

    private ?int $partnerId = null;

    private ?int $otherPartnerId = null;

    #[Test]
    public function site_changes_to_identity_ownership_and_address_are_logged(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createSite();
            $otherPartner = $this->createPartner('Reassigned Owner');
            $this->otherPartnerId = $otherPartner->id;

            $site->update([
                'name' => 'Renamed Dock',
                'gln' => '0614141777770',
                'dea_number' => 'RS1234563',
                'hin_number' => 'H123456789',
                'duns_number' => '80373640412345',
                'chemical_reg_number' => 'CR-LOG-001',
                'is_active' => false,
                'city' => 'Springfield',
                'zipcode' => '62701',
            ]);

            $this->assertNotNull(
                $this->latestActivityFor($site),
                'Updating a site must write an activity record.',
            );

            $changes = $this->latestChangesFor($site);
            $attributes = $changes['attributes'] ?? [];
            $old = $changes['old'] ?? [];

            $this->assertSame('Renamed Dock', $attributes['name'] ?? null);
            $this->assertSame('0614141777770', $attributes['gln'] ?? null);
            $this->assertSame('RS1234563', $attributes['dea_number'] ?? null);
            $this->assertSame('H123456789', $attributes['hin_number'] ?? null);
            $this->assertSame('80373640412345', $attributes['duns_number'] ?? null);
            $this->assertSame('CR-LOG-001', $attributes['chemical_reg_number'] ?? null);
            $this->assertFalse((bool) ($attributes['is_active'] ?? true));
            $this->assertSame('Springfield', $attributes['city'] ?? null);
            $this->assertSame('62701', $attributes['zipcode'] ?? null);

            $this->assertSame('Audited Dock', $old['name'] ?? null);
            $this->assertTrue((bool) ($old['is_active'] ?? false));

            // Ownership is the "who owned that dock" half of the question.
            $site->update(['trading_partner_id' => $otherPartner->id]);
            $ownershipChanges = $this->latestChangesFor($site);
            $this->assertSame(
                (int) $otherPartner->id,
                (int) ($ownershipChanges['attributes']['trading_partner_id'] ?? 0),
            );
            $this->assertSame(
                (int) $this->partnerId,
                (int) ($ownershipChanges['old']['trading_partner_id'] ?? 0),
            );

            $site->update(['is_headquarters' => true]);
            $hqChanges = $this->latestChangesFor($site);
            $this->assertTrue((bool) ($hqChanges['attributes']['is_headquarters'] ?? false));

            $site->update(['street_address' => '500 Inspector Way', 'state' => 'IL']);
            $addressChanges = $this->latestChangesFor($site);
            $this->assertSame('500 Inspector Way', $addressChanges['attributes']['street_address'] ?? null);
            $this->assertSame('IL', $addressChanges['attributes']['state'] ?? null);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function site_activity_ignores_untracked_fields_and_unchanged_saves(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $site = $this->createSite();

            $site->update(['name' => 'First Rename']);
            $baselineId = $this->latestActivityFor($site)?->getKey();
            $this->assertNotNull($baselineId);

            // Description and timezone are not part of the regulated identity, ownership,
            // activation or address history, so they must not add audit noise.
            $site->update([
                'description' => 'Internal note '.uniqid(),
                'timezone' => 'America/Chicago',
            ]);
            $this->assertSame(
                $baselineId,
                $this->latestActivityFor($site)?->getKey(),
                'Untracked fields must not write an activity record.',
            );

            $site->update(['name' => 'First Rename']);
            $this->assertSame(
                $baselineId,
                $this->latestActivityFor($site)?->getKey(),
                'A save with no dirty tracked attribute must not write an activity record.',
            );
        } finally {
            $this->cleanup();
        }
    }

    private function createSite(): Site
    {
        $partner = $this->createPartner('Audited Partner');
        $this->partnerId = $partner->id;

        $site = Site::query()->create([
            'trading_partner_id' => $partner->id,
            'name' => 'Audited Dock',
            'code' => 'AUD-'.strtoupper(uniqid()),
            'gln' => fake()->unique()->numerify('#############'),
            'street_address' => '1 Audit Lane',
            'city' => 'Austin',
            'state' => 'TX',
            'zipcode' => '78701',
            'country_code' => 'US',
            'is_headquarters' => false,
            'is_active' => true,
            'is_organization_facility' => false,
        ]);

        $this->siteId = $site->id;

        return $site;
    }

    private function createPartner(string $label): TradingPartner
    {
        return TradingPartner::query()->create([
            'name' => $label.' '.uniqid(),
            'gln' => fake()->unique()->numerify('#############'),
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
    }

    private function latestActivityFor(Site $site): ?Activity
    {
        return Activity::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->getKey())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{attributes?: array<string, mixed>, old?: array<string, mixed>}
     */
    private function latestChangesFor(Site $site): array
    {
        return $this->latestActivityFor($site)?->attribute_changes?->toArray() ?? [];
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->siteId !== null) {
                Activity::query()
                    ->where('subject_type', (new Site)->getMorphClass())
                    ->where('subject_id', $this->siteId)
                    ->delete();

                Site::query()->whereKey($this->siteId)->delete();
            }

            $partnerIds = array_filter([$this->partnerId, $this->otherPartnerId]);

            if ($partnerIds !== []) {
                Site::query()->whereIn('trading_partner_id', $partnerIds)->delete();
                TradingPartner::query()->whereIn('id', $partnerIds)->delete();
            }

            tenancy()->end();
        }

        $this->siteId = null;
        $this->partnerId = null;
        $this->otherPartnerId = null;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }
}

<?php

namespace App\Actions\Demo;

use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Enums\DeviceType;
use App\Enums\FacilityType;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Models\AtpLicense;
use App\Models\Device;
use App\Models\Fda\FdaProductPackaging;
use App\Models\LocationDevice;
use App\Models\OutboundConnection;
use App\Models\Product;
use App\Models\ReadPoint;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;

/**
 * Idempotent sample master data for the demo tenant.
 */
final class SeedMasterData
{
    private const FALLBACK_ORG_GLN = '0614141999901';

    public function handle(): void
    {
        if (! TradingPartner::query()->exists()) {
            TradingPartner::query()->create([
                'name' => 'Demo Wholesaler',
                'gln' => '0614141000001',
                'partner_type' => PartnerType::Wholesaler,
                'street_address' => '100 Demo Street',
                'city' => 'Austin',
                'state' => 'TX',
                'zipcode' => '78701',
                'country_code' => 'US',
                'is_active' => true,
            ]);

            TradingPartner::query()->create([
                'name' => 'Demo Manufacturer',
                'gln' => '0614141000002',
                'partner_type' => PartnerType::Manufacturer,
                'is_active' => true,
            ]);
        }

        // GS1 Mod-10: body 061414100000 → check digit 5. The prior seed used …0003
        // (invalid check digit, no SGLN); heal that row in place so shipping can author
        // destinationList without inventing a company-prefix split.
        $downstreamPharmacy = TradingPartner::query()->firstOrCreate(
            ['gln' => '0614141000005'],
            [
                'name' => 'Demo Downstream Pharmacy',
                'sgln' => 'urn:epc:id:sgln:0614141.00000.0',
                'partner_type' => PartnerType::Pharmacy,
                'is_active' => true,
            ],
        );
        $downstreamPharmacy->forceFill([
            'name' => 'Demo Downstream Pharmacy',
            'sgln' => 'urn:epc:id:sgln:0614141.00000.0',
            'is_active' => true,
        ])->save();

        TradingPartner::query()
            ->where('gln', '0614141000003')
            ->whereKeyNot($downstreamPharmacy->getKey())
            ->update([
                'is_active' => false,
                'name' => '[LEGACY] Demo Downstream Pharmacy',
            ]);

        $demoManufacturer = TradingPartner::query()->where('partner_type', PartnerType::Manufacturer)->first();

        $fdaPackaging = FdaProductPackaging::query()->where('gtin', '00312345678901')->first();

        $atorvastatin = Product::query()->firstOrCreate(
            ['gtin' => '00312345678901'],
            [
                'fda_product_id' => $fdaPackaging?->fda_product_id,
                'fda_product_packaging_id' => $fdaPackaging?->id,
                'name' => 'Demo Atorvastatin 20 mg',
                'dosage_form' => 'tablet',
                'strength' => '20 mg',
                'trading_partner_id' => $demoManufacturer?->id,
                'ndc' => '12345-678-90',
                'package_ndc' => $fdaPackaging?->package_ndc,
                'ndc11' => $fdaPackaging?->ndc11,
                'is_active' => true,
            ]
        );

        if ($atorvastatin->fda_product_id === null && $fdaPackaging?->fda_product_id) {
            $atorvastatin->update([
                'fda_product_id' => $fdaPackaging->fda_product_id,
                'fda_product_packaging_id' => $fdaPackaging->id,
                'package_ndc' => $fdaPackaging->package_ndc,
                'ndc11' => $fdaPackaging->ndc11,
            ]);
        }

        Product::query()->firstOrCreate(
            ['gtin' => '00398765432109'],
            [
                'name' => 'Demo Amoxicillin 500 mg',
                'dosage_form' => 'capsule',
                'strength' => '500 mg',
                'trading_partner_id' => $demoManufacturer?->id,
                'ndc' => '98765-432-10',
                'is_active' => true,
            ]
        );

        // Only the demo wholesaler gets a seeded HQ site. Falling back to the first
        // partner on the table would hand an HQ location — and its GLN — to whichever
        // real partner a tenant happened to import first.
        $demoWholesaler = TradingPartner::query()->where('gln', '0614141000001')->first();

        if ($demoWholesaler !== null) {
            app(CreateHqSiteForTradingPartner::class)->handle($demoWholesaler);
        }

        $site = $this->ensureOwnedOrganizationHqSite();

        $this->seedTenantOrganizationDefaults($site);

        if (! $site->readPoints()->exists()) {
            ReadPoint::query()->create([
                'site_id' => $site->id,
                'name' => 'Receiving Dock',
                'code' => 'DOCK-1',
                'is_active' => true,
            ]);

            ReadPoint::query()->create([
                'site_id' => $site->id,
                'name' => 'Pharmacy Counter',
                'code' => 'RX-1',
                'is_active' => true,
            ]);
        }

        AtpLicense::query()->firstOrCreate(
            ['license_state' => 'TX', 'license_number' => 'ATP-DEMO-001'],
            [
                'site_id' => $site->id,
                'facility_type' => FacilityType::Wdd,
                'license_expiration_date' => now()->addYear()->toDateString(),
                'reporting_year' => now()->year,
            ]
        );

        LocationDevice::query()->firstOrCreate(
            ['gln' => '0614141000099'],
            [
                'site_id' => $site->id,
                'name' => 'Receiving Dock GLN',
                'description' => 'Primary inbound GLN location',
            ]
        );

        Device::query()->firstOrCreate(
            ['serial_number' => 'DEMO-SCN-001'],
            [
                'name' => 'Demo Handheld Scanner',
                'device_type' => DeviceType::Scanner,
                'manufacturer' => 'Zebra',
                'model' => 'DS3608',
                'site_id' => $site->id,
                'is_active' => true,
            ]
        );

        // Outbound ship demo: Demo Downstream Pharmacy partner + optional HTTPS connection
        // (active only for outbound-capable tenant profiles such as DrugWholesaler).
        $this->seedOutboundShipDemo();
    }

    /**
     * Idempotent outbound ship demo data for Ship Order v1.
     *
     * Demo Downstream Pharmacy (0614141000005) is always ensured above.
     * When the tenant profile supports outbound integrations, seed an active HTTPS
     * OutboundConnection to https://example.com/epcis-inbound linked to that partner.
     * Non-outbound profiles keep the connection inactive if it already exists.
     *
     * An earlier seed pointed this connection at the …0003 partner, which is now the
     * deactivated legacy row. The connection is re-pointed on every run, so a tenant
     * seeded before the GLN was healed does not keep addressing shipments to a partner
     * that carries an invalid GLN and no SGLN.
     */
    private function seedOutboundShipDemo(): void
    {
        $downstream = TradingPartner::query()->where('gln', '0614141000005')->first();

        if ($downstream === null) {
            return;
        }

        $tenant = tenant();
        $supportsOutbound = $tenant !== null
            && TenantFeatures::forTenant($tenant)->supportsOutboundIntegrations();

        $connection = OutboundConnection::query()->where('name', 'Demo Downstream Pharmacy HTTPS')->first();

        if ($connection !== null) {
            try {
                $connection->credentials;
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                // Shared demo tenants can carry credentials encrypted under another APP_KEY.
                // Drop the row so the seed can recreate it without breaking updateOrCreate.
                $connection->delete();
                $connection = null;
            }
        }

        $connection = OutboundConnection::query()->updateOrCreate(
            ['name' => 'Demo Downstream Pharmacy HTTPS'],
            [
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $downstream->id,
                'is_active' => $supportsOutbound,
                'settings' => ['endpoint_url' => 'https://example.com/epcis-inbound'],
                'credentials' => ['webhook_token' => 'demo-outbound-token'],
            ]
        );

        if ((int) $connection->trading_partner_id !== (int) $downstream->id) {
            $connection->forceFill(['trading_partner_id' => $downstream->id])->save();
        }
    }

    /**
     * Organization HQ for receive/ship defaults — owned site (null trading_partner_id).
     */
    private function ensureOwnedOrganizationHqSite(): Site
    {
        $settings = TenantSettings::forTenant(tenant());
        $ownedGln = $settings->gln() ?: self::FALLBACK_ORG_GLN;

        if ($settings->hasOrganizationAddress()) {
            $address = $settings->organizationAddress();
            if (blank($address['country_code'] ?? null)) {
                $address['country_code'] = 'US';
            }
        } else {
            $address = $this->randomIllinoisAddress();
            $settings->saveOrganization($address);
        }

        $siteAttributes = [
            'trading_partner_id' => null,
            'name' => 'Demo Organization HQ',
            'code' => 'ORG-HQ',
            'gln' => $ownedGln,
            'street_address' => $address['street_address'],
            'street_address_2' => $address['street_address_2'] ?? null,
            'city' => $address['city'],
            'state' => $address['state'],
            'zipcode' => $address['zipcode'],
            'country_code' => $address['country_code'] ?? 'US',
            'is_headquarters' => true,
            'is_active' => true,
            'is_organization_facility' => true,
        ];

        $existing = Site::query()
            ->whereNull('trading_partner_id')
            ->where(function ($query) use ($ownedGln): void {
                $query->where('code', 'ORG-HQ')
                    ->orWhere('name', 'Demo Organization HQ')
                    ->orWhere('gln', $ownedGln)
                    ->orWhere('gln', self::FALLBACK_ORG_GLN);
            })
            ->orderByRaw("CASE WHEN code = 'ORG-HQ' THEN 0 WHEN gln = ? THEN 1 ELSE 2 END", [$ownedGln])
            ->orderBy('id')
            ->first();

        $siteWithGln = Site::query()->where('gln', $ownedGln)->first();

        if (
            $siteWithGln !== null
            && $siteWithGln->trading_partner_id === null
            && ($existing === null || (int) $existing->getKey() !== (int) $siteWithGln->getKey())
        ) {
            $existing = $siteWithGln;
        }

        if ($siteWithGln !== null && $siteWithGln->trading_partner_id !== null) {
            // Org GLN already taken by a partner site — keep fallback GLN on owned HQ.
            $siteAttributes['gln'] = $existing?->gln ?: self::FALLBACK_ORG_GLN;
            if ($siteAttributes['gln'] === $ownedGln) {
                $siteAttributes['gln'] = self::FALLBACK_ORG_GLN;
            }
        }

        if ($existing !== null) {
            $existing->forceFill($siteAttributes)->save();

            return $existing->fresh() ?? $existing;
        }

        return Site::query()->create($siteAttributes);
    }

    /**
     * @return array{
     *     street_address: string,
     *     street_address_2: null,
     *     city: string,
     *     state: string,
     *     zipcode: string,
     *     country_code: string,
     * }
     */
    private function randomIllinoisAddress(): array
    {
        $cities = [
            ['Chicago', '60601'],
            ['Springfield', '62701'],
            ['Naperville', '60540'],
            ['Peoria', '61602'],
            ['Rockford', '61101'],
        ];

        [$city, $zip] = $cities[array_rand($cities)];

        return [
            'street_address' => fake()->numberBetween(100, 9999).' '.fake()->streetName(),
            'street_address_2' => null,
            'city' => $city,
            'state' => 'IL',
            'zipcode' => $zip,
            'country_code' => 'US',
        ];
    }

    /**
     * Wire org GLN + default receive/ship sites from the HQ site when unset.
     */
    private function seedTenantOrganizationDefaults(Site $site): void
    {
        $tenant = tenant();

        if ($tenant === null || blank($site->gln)) {
            return;
        }

        $settings = TenantSettings::forTenant($tenant);
        $changed = false;
        $siteId = (int) $site->getKey();

        if (blank($settings->gln())) {
            $settings->setGln((string) $site->gln);
            $changed = true;
        }

        if (blank($settings->companyPrefix())) {
            $prefix = $this->deriveCompanyPrefixFromGln($settings->gln() ?? (string) $site->gln);
            if ($prefix !== null) {
                $settings->setCompanyPrefix($prefix);
                $changed = true;
            }
        }

        // Demo seed always pins org defaults to the owned organization HQ site.
        if ($settings->defaultReceiveSiteId() !== $siteId) {
            $settings->setDefaultReceiveSiteId($siteId);
            $changed = true;
        }

        if ($settings->defaultShipFromSiteId() !== $siteId) {
            $settings->setDefaultShipFromSiteId($siteId);
            $changed = true;
        }

        if ($changed) {
            $tenant->save();
        }
    }

    private function deriveCompanyPrefixFromGln(?string $gln): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $gln) ?? '';

        if (strlen($digits) !== 13) {
            return null;
        }

        foreach ([7, 6, 8, 9, 10, 11] as $length) {
            $prefix = substr($digits, 0, $length);

            try {
                TenantSettings::assertValidCompanyPrefix($prefix, $digits);

                return $prefix;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }
}

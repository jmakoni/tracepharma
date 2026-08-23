<?php

namespace Tests\Unit\Support;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Support\TenantOnboarding;
use App\Support\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    #[Test]
    public function save_organization_persists_gln_receiving_state_and_default_site_ids(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'receiving_state' => 'IL',
                'default_receive_site_id' => 12,
                'default_ship_from_site_id' => 34,
                'compliance_contact_name' => 'Pat Compliance',
                'compliance_contact_email' => 'compliance@example.test',
                'it_contact_name' => 'Sam IT',
                'it_contact_email' => 'it@example.test',
                'serialization_contact_name' => 'Alex Serialization',
                'serialization_contact_email' => 'serialization@example.test',
            ]);

            $fresh = $tenant->fresh();
            $settings = TenantSettings::forTenant($fresh);

            $this->assertSame('0366159000010', $settings->gln());
            $this->assertSame('IL', $settings->receivingState());
            $this->assertSame('IL', $fresh->receiving_state);
            $this->assertSame(12, $settings->defaultReceiveSiteId());
            $this->assertSame(34, $settings->defaultShipFromSiteId());
            $this->assertSame('Pat Compliance', $settings->complianceContactName());
            $this->assertSame('compliance@example.test', $settings->complianceContactEmail());
            $this->assertSame('Sam IT', $settings->itContactName());
            $this->assertSame('it@example.test', $settings->itContactEmail());
            $this->assertSame('Alex Serialization', $settings->serializationContactName());
            $this->assertSame('serialization@example.test', $settings->serializationContactEmail());

            // gln is a custom column; receiving_state + settings are stancl virtual (data JSON).
            $this->assertSame('0366159000010', $fresh->getAttribute('gln'));
            $this->assertSame('IL', $fresh->getAttribute('receiving_state'));
            $this->assertSame(12, data_get($fresh->getAttribute('settings'), 'default_receive_site_id'));
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function job_roles_enabled_defaults_false_and_persists(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            $settings = TenantSettings::forTenant($tenant);
            $this->assertFalse($settings->jobRolesEnabled());

            $settings->saveOrganization(['job_roles_enabled' => true]);

            $this->assertTrue(TenantSettings::forTenant($tenant->fresh())->jobRolesEnabled());
            $this->assertTrue(data_get($tenant->fresh()->getAttribute('settings'), 'access.job_roles_enabled'));
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function wms_bridge_api_key_is_encrypted_at_rest_and_round_trips(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            TenantSettings::forTenant($tenant)->setWmsBridgeApiKey('secret-bridge-key');
            $tenant->save();

            $stored = data_get($tenant->fresh()->getAttribute('settings'), 'integrations.wms_bridge_api_key');
            $this->assertIsString($stored);
            $this->assertNotSame('secret-bridge-key', $stored);

            $this->assertSame(
                'secret-bridge-key',
                TenantSettings::forTenant($tenant->fresh())->wmsBridgeApiKey(),
            );
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function vrs_responder_api_key_is_encrypted_at_rest_and_round_trips(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            TenantSettings::forTenant($tenant)->setVrsResponderApiKey('secret-responder-key');
            $tenant->save();

            $stored = data_get($tenant->fresh()->getAttribute('settings'), 'integrations.vrs_responder_api_key');
            $this->assertIsString($stored);
            $this->assertNotSame('secret-responder-key', $stored);

            $this->assertSame(
                'secret-responder-key',
                TenantSettings::forTenant($tenant->fresh())->vrsResponderApiKey(),
            );
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function l3_settings_persist_under_settings_json(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'l3_enabled' => true,
                'l3_provider' => 'tracelink',
                'l3_endpoint_url' => 'https://l3.example.test/events',
            ]);

            $settings = TenantSettings::forTenant($tenant->fresh());

            $this->assertTrue($settings->l3Enabled());
            $this->assertSame('tracelink', $settings->l3Provider());
            $this->assertSame('https://l3.example.test/events', $settings->l3EndpointUrl());
            $this->assertTrue(data_get($tenant->fresh()->getAttribute('settings'), 'l3.enabled'));
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function l3_endpoint_url_rejects_userinfo_credentials(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            $this->expectException(\InvalidArgumentException::class);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'l3_endpoint_url' => 'https://apikey:secret@l3.example.test/events',
            ]);
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function wms_receive_confirm_url_requires_https_and_rejects_private_hosts(): void
    {
        TenantSettings::assertWmsReceiveConfirmUrlWithoutUserinfo(null);
        TenantSettings::assertWmsReceiveConfirmUrlWithoutUserinfo('');
        TenantSettings::assertWmsReceiveConfirmUrlWithoutUserinfo('https://wms.example.test/receive-confirm');

        $rejected = [
            'http://wms.example.test/receive-confirm',
            'https://user:secret@wms.example.test/receive-confirm',
            'https://127.0.0.1/receive-confirm',
            'https://localhost/receive-confirm',
            'https://10.1.2.3/receive-confirm',
            'https://172.16.0.8/receive-confirm',
            'https://192.168.1.20/receive-confirm',
            'https://169.254.169.254/latest/meta-data',
            'https://[::1]/receive-confirm',
            'https://metadata.google.internal/computeMetadata/v1/',
        ];

        foreach ($rejected as $url) {
            try {
                TenantSettings::assertWmsReceiveConfirmUrlWithoutUserinfo($url);
                $this->fail('Expected WMS receive-confirm URL to be rejected: '.$url);
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function wms_receive_confirm_url_rejects_ipv4_mapped_loopback_literal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantSettings::assertWmsReceiveConfirmUrlWithoutUserinfo('https://[::ffff:127.0.0.1]/receive-confirm');
    }

    #[Test]
    public function wms_resolved_addresses_deny_loopback_and_link_local_but_allow_rfc1918(): void
    {
        $this->assertTrue(TenantSettings::isDeniedWmsResolvedAddress('127.0.0.1'));
        $this->assertTrue(TenantSettings::isDeniedWmsResolvedAddress('127.1.2.3'));
        $this->assertTrue(TenantSettings::isDeniedWmsResolvedAddress('169.254.169.254'));
        $this->assertTrue(TenantSettings::isDeniedWmsResolvedAddress('169.254.1.1'));
        $this->assertTrue(TenantSettings::isDeniedWmsResolvedAddress('::1'));
        $this->assertTrue(TenantSettings::isDeniedWmsResolvedAddress('fe80::1'));
        $this->assertTrue(TenantSettings::isDeniedWmsResolvedAddress('::ffff:127.0.0.1'));
        $this->assertFalse(TenantSettings::isDeniedWmsResolvedAddress('10.1.2.3'));
        $this->assertFalse(TenantSettings::isDeniedWmsResolvedAddress('172.16.0.8'));
        $this->assertFalse(TenantSettings::isDeniedWmsResolvedAddress('192.168.1.20'));
        $this->assertFalse(TenantSettings::isDeniedWmsResolvedAddress('8.8.8.8'));
    }

    #[Test]
    public function wms_receive_confirm_url_save_rejects_http_loopback(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            $this->expectException(\InvalidArgumentException::class);

            TenantSettings::forTenant($tenant)->saveOrganization([
                'wms_receive_confirm_url' => 'http://127.0.0.1/receive-confirm',
            ]);
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function save_organization_persists_company_prefix_column(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000010',
                'company_prefix' => '036615',
            ]);

            $settings = TenantSettings::forTenant($tenant->fresh());

            $this->assertSame('036615', $settings->companyPrefix());
            $this->assertSame('036615', $tenant->fresh()->getAttribute('company_prefix'));
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function company_prefix_rejects_invalid_length_and_gln_mismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantSettings::assertValidCompanyPrefix('12345');
    }

    #[Test]
    public function company_prefix_must_match_gln_body_when_both_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TenantSettings::assertValidCompanyPrefix('999999', '0366159000010');
    }

    #[Test]
    public function company_prefix_normalizes_to_digits(): void
    {
        $this->assertSame('036615', TenantSettings::normalizeCompanyPrefix('036-615'));
        $this->assertNull(TenantSettings::normalizeCompanyPrefix('  '));
        TenantSettings::assertValidCompanyPrefix('036615', '0366159000010');
    }

    #[Test]
    public function onboarding_progress_and_dismissed_at_round_trip_on_settings_json(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            $settings = TenantSettings::forTenant($tenant);
            $settings->setOnboarding(['org_gln' => true, 'step' => 2]);
            $settings->setOnboardingDismissedAt(now()->startOfSecond());
            $tenant->save();

            $fresh = TenantSettings::forTenant($tenant->fresh());

            $this->assertSame(['org_gln' => true, 'step' => 2], $fresh->onboarding());
            $this->assertNotNull($fresh->onboardingDismissedAt());
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function acknowledge_outbound_deferred_persists_timestamp(): void
    {
        $tenant = $this->createCentralTenant();

        try {
            $settings = TenantSettings::forTenant($tenant);
            $settings->acknowledgeOutboundDeferred(now()->startOfSecond());
            $tenant->save();

            $fresh = TenantSettings::forTenant($tenant->fresh());

            $this->assertNotNull($fresh->outboundChoreographyDeferredAt());
        } finally {
            $this->deleteCentralTenant($tenant);
        }
    }

    #[Test]
    public function default_site_resolvers_return_null_without_tenancy(): void
    {
        $tenant = new Tenant([
            'name' => 'No Tenancy',
            'profile' => TenantProfile::DrugWholesaler,
        ]);
        $tenant->setAttribute('settings', [
            'default_receive_site_id' => 99,
            'default_ship_from_site_id' => 100,
        ]);

        $settings = TenantSettings::forTenant($tenant);

        $this->assertSame(99, $settings->defaultReceiveSiteId());
        $this->assertNull($settings->defaultReceiveSite());
        $this->assertNull($settings->defaultShipFromSite());
    }

    #[Test]
    public function wholesaler_onboarding_lists_full_checklist_and_tracks_critical_items(): void
    {
        $tenant = new Tenant([
            'name' => 'Wholesaler',
            'profile' => TenantProfile::DrugWholesaler,
            'gln' => '0366159000010',
            'receiving_state' => 'IL',
        ]);
        $tenant->setAttribute('settings', [
            'default_receive_site_id' => 1,
            'default_ship_from_site_id' => 1,
        ]);

        $onboarding = TenantOnboarding::forTenant($tenant);
        $ids = array_column($onboarding->items(), 'id');

        $this->assertContains('org_gln', $ids);
        $this->assertContains('default_ship_from_site', $ids);
        $this->assertContains('downstream_partner', $ids);
        $this->assertContains('outbound_configured', $ids);
        $this->assertContains('receive_proven', $ids);

        // Critical needs site GLNs from tenant DB — without tenancy, incomplete.
        $this->assertFalse($onboarding->isCriticalComplete());
        $this->assertFalse($onboarding->isComplete());
        $this->assertGreaterThan(0, $onboarding->score());
        $this->assertLessThan(100, $onboarding->score());
    }

    #[Test]
    public function pharmacy_onboarding_omits_ship_from_downstream_and_outbound(): void
    {
        $tenant = new Tenant([
            'name' => 'Pharmacy',
            'profile' => TenantProfile::Pharmacy,
            'gln' => '0366159000010',
        ]);

        $ids = array_column(TenantOnboarding::forTenant($tenant)->items(), 'id');

        $this->assertContains('org_gln', $ids);
        $this->assertContains('default_receive_site', $ids);
        $this->assertContains('receive_proven', $ids);
        $this->assertNotContains('default_ship_from_site', $ids);
        $this->assertNotContains('downstream_partner', $ids);
        $this->assertNotContains('outbound_configured', $ids);
    }

    private function createCentralTenant(): Tenant
    {
        $id = (string) Str::uuid();

        return Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
            'id' => $id,
            'name' => 'Settings Unit '.$id,
            'profile' => TenantProfile::DrugWholesaler,
            'status' => 'active',
            'tenancy_db_name' => 'tenant_settings_test_'.str_replace('-', '', $id),
        ]));
    }

    private function deleteCentralTenant(Tenant $tenant): void
    {
        Tenant::withoutEvents(fn () => $tenant->delete());
    }
}

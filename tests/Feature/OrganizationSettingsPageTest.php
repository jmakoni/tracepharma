<?php

namespace Tests\Feature;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OrganizationSettings;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationSettingsPageTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $siteId = null;

    private ?TenantProfile $priorProfile = null;

    #[Test]
    public function save_persists_organization_settings_via_tenant_settings(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Org Settings Receive Site',
                'gln' => '0366159000026',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'gln' => '0366159000026',
                    'company_prefix' => '036615',
                    'receiving_state' => 'IL',
                    'street_address' => '100 W Randolph St',
                    'street_address_2' => 'Suite 400',
                    'city' => 'Chicago',
                    'state' => 'IL',
                    'zipcode' => '60601',
                    'country_code' => 'US',
                    'default_receive_site_id' => $this->siteId,
                    'compliance_contact_name' => 'Pat Compliance',
                    'compliance_contact_email' => 'compliance@example.test',
                    'it_contact_name' => 'Sam IT',
                    'it_contact_email' => 'it@example.test',
                    'serialization_contact_name' => 'Alex Serialization',
                    'serialization_contact_email' => 'serialization@example.test',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $settings = TenantSettings::forTenant($tenant->fresh());

            $this->assertSame('0366159000026', $settings->gln());
            $this->assertSame('036615', $settings->companyPrefix());
            $this->assertSame('IL', $settings->receivingState());
            $this->assertSame('100 W Randolph St', $settings->streetAddress());
            $this->assertSame('Suite 400', $settings->streetAddress2());
            $this->assertSame('Chicago', $settings->city());
            $this->assertSame('IL', $settings->state());
            $this->assertSame('60601', $settings->zipcode());
            $this->assertSame('US', $settings->countryCode());
            $this->assertTrue($settings->hasOrganizationAddress());
            $this->assertSame($this->siteId, $settings->defaultReceiveSiteId());
            $this->assertSame('Pat Compliance', $settings->complianceContactName());
            $this->assertSame('compliance@example.test', $settings->complianceContactEmail());
            $this->assertSame('Sam IT', $settings->itContactName());
            $this->assertSame('it@example.test', $settings->itContactEmail());
            $this->assertSame('Alex Serialization', $settings->serializationContactName());
            $this->assertSame('serialization@example.test', $settings->serializationContactEmail());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function save_persists_require_pure_epcis_document_toggle(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $prior = TenantSettings::forTenant($tenant)->requirePureEpcisDocument();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'require_pure_epcis_document' => true,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertTrue(TenantSettings::forTenant($tenant->fresh())->requirePureEpcisDocument());

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'require_pure_epcis_document' => false,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertFalse(TenantSettings::forTenant($tenant->fresh())->requirePureEpcisDocument());
        } finally {
            TenantSettings::forTenant($tenant)->setRequirePureEpcisDocument($prior);
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function save_persists_block_send_on_atp_gap_toggle(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $settings = TenantSettings::forTenant($tenant);
        $prior = $settings->blockSendOnAtpGap();
        $priorReceiveSiteId = $settings->defaultReceiveSiteId();
        $priorGln = $settings->gln();

        try {
            $settings->saveOrganization([
                'default_receive_site_id' => null,
                'gln' => null,
                'block_send_on_atp_gap' => true,
            ]);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'gln' => null,
                    'block_send_on_atp_gap' => false,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertFalse(TenantSettings::forTenant($tenant->fresh())->blockSendOnAtpGap());

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'gln' => null,
                    'block_send_on_atp_gap' => true,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertTrue(TenantSettings::forTenant($tenant->fresh())->blockSendOnAtpGap());
        } finally {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'block_send_on_atp_gap' => $prior,
                'default_receive_site_id' => $priorReceiveSiteId,
                'gln' => $priorGln,
            ]);
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function save_persists_block_receive_on_destination_gln_mismatch_toggle(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $settings = TenantSettings::forTenant($tenant);
        $prior = $settings->blockReceiveOnDestinationGlnMismatch();
        $priorReceiveSiteId = $settings->defaultReceiveSiteId();

        try {
            // demo2 may retain a stale default receive site id after facility GLN cleanup.
            $settings->saveOrganization([
                'default_receive_site_id' => null,
            ]);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'block_receive_on_destination_gln_mismatch' => true,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertTrue(TenantSettings::forTenant($tenant->fresh())->blockReceiveOnDestinationGlnMismatch());

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'block_receive_on_destination_gln_mismatch' => false,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertFalse(TenantSettings::forTenant($tenant->fresh())->blockReceiveOnDestinationGlnMismatch());
        } finally {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'block_receive_on_destination_gln_mismatch' => $prior,
                'default_receive_site_id' => $priorReceiveSiteId,
            ]);
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function save_persists_match_inbound_ship_to_site_toggle_default_off(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $settings = TenantSettings::forTenant($tenant);
        $prior = $settings->matchInboundShipToSite();
        $priorReceiveSiteId = $settings->defaultReceiveSiteId();

        try {
            $settings->saveOrganization([
                'default_receive_site_id' => null,
                'match_inbound_ship_to_site' => false,
            ]);

            $this->assertFalse(TenantSettings::forTenant($tenant->fresh())->matchInboundShipToSite());

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'match_inbound_ship_to_site' => true,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertTrue(TenantSettings::forTenant($tenant->fresh())->matchInboundShipToSite());

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'match_inbound_ship_to_site' => false,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertFalse(TenantSettings::forTenant($tenant->fresh())->matchInboundShipToSite());
        } finally {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'match_inbound_ship_to_site' => $prior,
                'default_receive_site_id' => $priorReceiveSiteId,
            ]);
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function save_persists_auto_open_receive_after_transfer_ship_toggle(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $settings = TenantSettings::forTenant($tenant);
        $prior = $settings->autoOpenReceiveAfterTransferShip();
        $priorReceiveSiteId = $settings->defaultReceiveSiteId();

        try {
            $settings->saveOrganization([
                'default_receive_site_id' => null,
            ]);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'auto_open_receive_after_transfer_ship' => true,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertTrue(TenantSettings::forTenant($tenant->fresh())->autoOpenReceiveAfterTransferShip());

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'default_receive_site_id' => null,
                    'auto_open_receive_after_transfer_ship' => false,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertFalse(TenantSettings::forTenant($tenant->fresh())->autoOpenReceiveAfterTransferShip());
        } finally {
            TenantSettings::forTenant($tenant)->saveOrganization([
                'auto_open_receive_after_transfer_ship' => $prior,
                'default_receive_site_id' => $priorReceiveSiteId,
            ]);
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function save_persists_auto_complete_asn_on_ready_toggle(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $settings = TenantSettings::forTenant($tenant);
        $prior = $settings->autoCompleteAsnOnReady();
        $priorPrefix = $settings->companyPrefix();

        try {
            $orgGln = preg_replace('/\D+/', '', (string) ($settings->gln() ?? '')) ?? '';
            if (strlen($orgGln) === 13) {
                // Match demo2 facility SGLN split (6-digit GCP), not 7-digit.
                $settings->setCompanyPrefix(substr($orgGln, 0, 6));
            }
            $settings->setAutoCompleteAsnOnReady(true);
            $tenant->saveQuietly();
            tenancy()->initialize($tenant->fresh());

            $user = User::factory()->create([
                'email' => 'asn-auto-complete-'.uniqid('', true).'@example.test',
            ]);
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(TenantSettings::forTenant($tenant->fresh())->autoCompleteAsnOnReady());

            Livewire::test(OrganizationSettings::class)
                ->assertFormSet([
                    'auto_complete_asn_on_ready' => true,
                ]);

            TenantSettings::forTenant($tenant)
                ->setAutoCompleteAsnOnReady(false)
                ->saveQuietly();
            $this->assertFalse(TenantSettings::forTenant($tenant->fresh())->autoCompleteAsnOnReady());

            Livewire::test(OrganizationSettings::class)
                ->assertFormSet([
                    'auto_complete_asn_on_ready' => false,
                ]);
        } finally {
            $restored = TenantSettings::forTenant($tenant)
                ->setAutoCompleteAsnOnReady($prior);
            // Prefer a GLN-aligned prefix over restoring a blank/stale value that
            // breaks later demo2 saveOrganization() calls in the same suite.
            $orgGln = preg_replace('/\D+/', '', (string) (TenantSettings::forTenant($tenant)->gln() ?? '')) ?? '';
            $aligned = strlen($orgGln) === 13 ? substr($orgGln, 0, 6) : $priorPrefix;
            $restored->setCompanyPrefix($aligned ?: $priorPrefix)->saveQuietly();
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function export_glns_downloads_csv_with_company_and_site_rows(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Org Export GLN Site',
                'gln' => '0366159000033',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            TenantSettings::forTenant($tenant)->saveOrganization([
                'gln' => '0366159000026',
            ]);

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $component = Livewire::test(OrganizationSettings::class)
                ->callAction('exportGlns')
                ->assertHasNoActionErrors()
                ->assertFileDownloaded(null, null, 'text/csv; charset=UTF-8');

            $content = base64_decode((string) data_get($component->effects, 'download.content'));
            $this->assertIsString($content);
            $this->assertStringContainsString('type,id,name,gln,sgln,is_headquarters', $content);
            $this->assertStringContainsString('company,', $content);
            $this->assertStringContainsString('0366159000026', $content);
            $this->assertMatchesRegularExpression(
                '/site,'.$this->siteId.',"?Org Export GLN Site"?,0366159000033,/',
                $content,
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function ship_from_site_field_hidden_for_pharmacy_visible_for_wholesaler(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->setProfile($tenant, TenantProfile::Pharmacy);

            Livewire::test(OrganizationSettings::class)
                ->assertFormFieldIsHidden('default_ship_from_site_id');

            $this->setProfile($tenant, TenantProfile::DrugWholesaler);

            Livewire::test(OrganizationSettings::class)
                ->assertFormFieldIsVisible('default_ship_from_site_id');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function manufacturer_can_see_and_save_external_l3_settings(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Manufacturer L3 Site',
                'gln' => '0366159000040',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);

            $this->setProfile($tenant, TenantProfile::Manufacturer);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->assertFormFieldIsVisible('l3_enabled')
                ->assertFormFieldIsVisible('l3_provider')
                ->assertFormFieldIsVisible('l3_endpoint_url')
                ->fillForm([
                    'gln' => '0366159000026',
                    'default_receive_site_id' => $this->siteId,
                    'default_ship_from_site_id' => $this->siteId,
                    'l3_enabled' => true,
                    'l3_provider' => 'systech',
                    'l3_endpoint_url' => 'https://l3.example.test/commission',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $settings = TenantSettings::forTenant($tenant->fresh());
            $this->assertTrue($settings->l3Enabled());
            $this->assertSame('systech', $settings->l3Provider());
            $this->assertSame('https://l3.example.test/commission', $settings->l3EndpointUrl());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function manufacturer_can_save_masked_l3_api_key(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Manufacturer L3 Key Site',
                'gln' => '0366159000057',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);

            $this->setProfile($tenant, TenantProfile::Manufacturer);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'gln' => '0366159000026',
                    'default_receive_site_id' => $this->siteId,
                    'default_ship_from_site_id' => $this->siteId,
                    'l3_enabled' => true,
                    'l3_endpoint_url' => 'https://l3.example.test/commission',
                    'l3_api_key' => 'super-secret-l3-key',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(
                'super-secret-l3-key',
                TenantSettings::forTenant($tenant->fresh())->l3ApiKey(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function manufacturer_rejects_l3_endpoint_url_with_userinfo(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $site = Site::query()->create([
                'name' => 'Manufacturer L3 URL Site',
                'gln' => '0366159000064',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Manufacturer);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);

            $this->setProfile($tenant, TenantProfile::Manufacturer);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->fillForm([
                    'gln' => '0366159000026',
                    'default_receive_site_id' => $this->siteId,
                    'default_ship_from_site_id' => $this->siteId,
                    'l3_enabled' => true,
                    'l3_endpoint_url' => 'https://user:pass@l3.example.test/commission',
                ])
                ->call('save')
                ->assertHasFormErrors(['l3_endpoint_url']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function organization_type_is_read_only_and_save_does_not_change_profile(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->setProfile($tenant, TenantProfile::Pharmacy);

            $site = Site::query()->create([
                'name' => 'Org Type Receive Site',
                'gln' => '0366159000026',
                'is_active' => true,
                'is_headquarters' => true,
                'is_organization_facility' => true,
            ]);
            $this->siteId = (int) $site->getKey();

            $profileBefore = $tenant->fresh()->profile;

            $user = $this->createOwner();
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(OrganizationSettings::class)
                ->assertFormFieldIsDisabled('organization_type')
                ->assertFormSet([
                    'organization_type' => 'Pharmacy',
                ])
                ->assertSee('Profile: Pharmacy')
                ->fillForm([
                    'gln' => '0366159000026',
                    'receiving_state' => 'IL',
                    'default_receive_site_id' => $this->siteId,
                    'compliance_contact_name' => 'Type Test',
                    'compliance_contact_email' => 'type-test@example.test',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(
                $profileBefore,
                $tenant->fresh()->profile,
                'Save must not mutate tenant profile via organization_type.',
            );
            $this->assertSame(TenantProfile::Pharmacy, $tenant->fresh()->profile);
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function setProfile(Tenant $tenant, TenantProfile $profile): void
    {
        $tenant->forceFill(['profile' => $profile])->save();
        tenancy()->end();
        tenancy()->initialize($tenant->fresh());
        Filament::setCurrentPanel(Filament::getPanel('app'));
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

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->priorProfile = $tenant->profile;

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if ($this->siteId !== null && tenancy()->initialized) {
            Site::query()->whereKey($this->siteId)->delete();
            $this->siteId = null;
        }

        if (tenancy()->initialized && $this->priorProfile !== null) {
            $restored = $tenant->fresh() ?? $tenant;
            $restored->forceFill(['profile' => $this->priorProfile])->save();
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->priorProfile = null;
    }
}

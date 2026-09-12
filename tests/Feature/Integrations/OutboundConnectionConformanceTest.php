<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\PromoteOutboundConnectionConformance;
use App\Enums\As2MdnAckMode;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\OutboundConnections\Pages\CreateOutboundConnection;
use App\Filament\App\Resources\OutboundConnections\Schemas\OutboundConnectionForm;
use App\Models\ConnectionGoLiveChecklist;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\OutboundNetworkProfile;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Integrations\IntegrationHealthMetrics;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use OpenSSLCertificate;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class OutboundConnectionConformanceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $checklistIds = [];

    #[Test]
    public function new_outbound_connection_starts_in_test_conformance_state(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Conformance Ladder HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'conformance_state' => OutboundConformanceState::Live,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $connection->refresh();

            $this->assertSame(OutboundConformanceState::Test, $connection->conformance_state);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function filament_create_outbound_connection_forces_test_conformance_state(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $name = 'Filament Conformance HTTPS '.Str::random(6);

            Livewire::test(CreateOutboundConnection::class)
                ->fillForm([
                    'name' => $name,
                    'serialization_provider' => SerializationProvider::CustomHttps->value,
                    'transport' => OutboundTransport::Https->value,
                    'is_active' => true,
                    'settings' => [
                        'kind' => OutboundConnectionKind::DirectPartner->value,
                        'endpoint_url' => 'https://partner.example/epcis',
                    ],
                    'tradingPartners' => [$this->createPartner()->getKey()],
                    'conformance_state' => OutboundConformanceState::Live->value,
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $connection = OutboundConnection::query()->where('name', $name)->first();
            $this->assertNotNull($connection);
            $this->connectionIds[] = (int) $connection->getKey();
            $this->assertSame(OutboundConformanceState::Test, $connection->conformance_state);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function owner_can_promote_conformance_one_step_at_a_time_through_ladder(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $action = app(PromoteOutboundConnectionConformance::class);

            $connection = OutboundConnection::query()->create([
                'name' => 'Promote Ladder HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $expectedSteps = [
                OutboundConformanceState::Conformance,
                OutboundConformanceState::FirstLiveLot,
                OutboundConformanceState::Hypercare,
            ];

            $this->assertSame(OutboundConformanceState::Test, $connection->conformance_state);

            foreach ($expectedSteps as $expectedState) {
                $connection = $action->promoteOneStep($connection, $owner);
                $this->assertSame($expectedState, $connection->conformance_state);
            }

            // Promotion to live is gated on the go-live checklist.
            $this->completeGoLiveChecklist($connection, $owner);

            $connection = $action->promoteOneStep($connection->fresh(), $owner);
            $this->assertSame(OutboundConformanceState::Live, $connection->conformance_state);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function direct_conformance_state_change_without_allow_flag_is_denied(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Guarded HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $connection->conformance_state = OutboundConformanceState::Live;

            try {
                $connection->save();
                $this->fail('Expected InvalidArgumentException when setting live without allowConformanceTransition.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    'conformance state may only change via promote or break-glass',
                    $e->getMessage(),
                );
            }

            $connection->refresh();
            $this->assertSame(OutboundConformanceState::Test, $connection->conformance_state);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function promote_one_step_when_already_live_throws(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $action = app(PromoteOutboundConnectionConformance::class);

            $connection = OutboundConnection::query()->create([
                'name' => 'Already Live HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            foreach ([
                OutboundConformanceState::Conformance,
                OutboundConformanceState::FirstLiveLot,
                OutboundConformanceState::Hypercare,
            ] as $_) {
                $connection = $action->promoteOneStep($connection, $owner);
            }

            $this->completeGoLiveChecklist($connection, $owner);
            $connection = $action->promoteOneStep($connection->fresh(), $owner);

            $this->assertSame(OutboundConformanceState::Live, $connection->conformance_state);

            try {
                $action->promoteOneStep($connection, $owner);
                $this->fail('Expected InvalidArgumentException when promoting from live.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('final state', $e->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function break_glass_to_live_with_permission_and_reason_succeeds_and_is_audited(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $action = app(PromoteOutboundConnectionConformance::class);

            $connection = OutboundConnection::query()->create([
                'name' => 'Break Glass HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $before = Activity::query()
                ->where('description', 'outbound_connection_conformance_break_glass')
                ->count();

            $reason = 'Emergency partner cutover for conformance test';

            $connection = $action->breakGlassToLive($connection, $owner, $reason);

            $this->assertSame(OutboundConformanceState::Live, $connection->conformance_state);

            $this->assertSame(
                $before + 1,
                Activity::query()
                    ->where('description', 'outbound_connection_conformance_break_glass')
                    ->count(),
            );

            $activity = Activity::query()
                ->where('description', 'outbound_connection_conformance_break_glass')
                ->latest('id')
                ->first();

            $this->assertNotNull($activity);
            $this->assertSame(OutboundConformanceState::Test->value, $activity->properties->get('from'));
            $this->assertSame(OutboundConformanceState::Live->value, $activity->properties->get('to'));
            $this->assertSame($reason, $activity->properties->get('reason'));
            $this->assertSame((int) $owner->getKey(), (int) $activity->causer_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function break_glass_to_live_with_empty_reason_is_denied(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $action = app(PromoteOutboundConnectionConformance::class);

            $connection = OutboundConnection::query()->create([
                'name' => 'Empty Reason HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            try {
                $action->breakGlassToLive($connection, $owner, '   ');
                $this->fail('Expected InvalidArgumentException for empty break-glass reason.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('non-empty reason', $e->getMessage());
            }

            $connection->refresh();
            $this->assertSame(OutboundConformanceState::Test, $connection->conformance_state);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function break_glass_to_live_without_permission_is_denied(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);

            $connection = OutboundConnection::query()->create([
                'name' => 'No Break Glass HTTPS',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $viewer = User::factory()->create([
                'email' => 'viewer-conformance-'.Str::uuid().'@example.com',
            ]);
            $viewer->assignRole(TenantRole::WmsIntegrationSpecialist->value);

            $action = app(PromoteOutboundConnectionConformance::class);

            try {
                $action->breakGlassToLive($connection, $viewer, 'Should not succeed');
                $this->fail('Expected AuthorizationException when user lacks break-glass permission.');
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('break-glass permission', $e->getMessage());
            }

            $connection->refresh();
            $this->assertSame(OutboundConformanceState::Test, $connection->conformance_state);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function as2_cert_expiring_within_warning_window_flags_cert_expiry_warning(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $certPem = $this->generateSelfSignedCertPem(15);

            $connection = OutboundConnection::query()->create([
                'name' => 'AS2 Cert Warning',
                'serialization_provider' => SerializationProvider::Axway,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner.example/as2',
                    'as2_from' => 'SENDER',
                    'as2_to' => 'RECEIVER',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
                'credentials' => [
                    'signing_cert_pem' => $certPem,
                ],
            ]);
            $this->connectionIds[] = (int) $connection->getKey();

            $connection->refresh();

            $this->assertNotNull($connection->as2CertExpiresAt());
            $this->assertTrue($connection->certExpiryWarning());

            $loaded = app(IntegrationHealthMetrics::class)
                ->outboundConnections()
                ->firstWhere('id', $connection->getKey());

            $this->assertNotNull($loaded);
            $this->assertTrue(filled($loaded->credentials['signing_cert_pem'] ?? null));
            $this->assertTrue($loaded->certExpiryWarning());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function create_form_renders_wizard_steps_without_customer_specific_copy(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            Livewire::test(CreateOutboundConnection::class)
                ->assertSee('Destination')
                ->assertSee('Customers')
                ->assertSee('Credentials');

            $copy = $this->formCopy(CreateOutboundConnection::class);

            $this->assertStringNotContainsString('Cardinal', $copy);
            $this->assertStringNotContainsString('Cencora', $copy);
            $this->assertStringNotContainsString('Xttrium', $copy);
            $this->assertStringNotContainsString('Serialization provider', $copy);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function network_profile_options_group_platform_hub_first(): void
    {
        $this->initializeDemo2Tenant();

        $createdIds = [];

        try {
            foreach ([
                ['network_slug' => 'tracepharma', 'environment' => 'demo', 'label' => 'TracePharma Demo Hub'],
                ['network_slug' => 'lspedia', 'environment' => 'demo', 'label' => 'LSPediA Demo'],
            ] as $row) {
                $profile = OutboundNetworkProfile::query()->firstOrCreate(
                    ['network_slug' => $row['network_slug'], 'environment' => $row['environment']],
                    [
                        'label' => $row['label'],
                        'default_transport' => OutboundTransport::Https->value,
                        'allowed_transports' => [OutboundTransport::Https->value],
                    ],
                );

                if ($profile->wasRecentlyCreated) {
                    $createdIds[] = $profile->getKey();
                }
            }

            $options = OutboundConnectionForm::groupedNetworkProfileOptions();

            $this->assertSame('TracePharma hub (this platform)', array_key_first($options));
            $this->assertArrayHasKey('External networks', $options);

            $platformIds = array_keys($options['TracePharma hub (this platform)']);
            $externalIds = array_keys($options['External networks']);

            $tracepharmaIds = OutboundNetworkProfile::query()
                ->where('network_slug', 'tracepharma')
                ->pluck('id')
                ->all();

            foreach ($tracepharmaIds as $id) {
                $this->assertContains($id, $platformIds);
                $this->assertNotContains($id, $externalIds);
            }
        } finally {
            if ($createdIds !== []) {
                OutboundNetworkProfile::query()->whereIn('id', $createdIds)->delete();
            }

            $this->cleanup();
        }
    }

    /**
     * All static operator-facing copy (labels, helpers, descriptions, radio options)
     * from the page's form schema — data-driven select options excluded on purpose.
     *
     * @param  class-string  $pageClass
     */
    private function formCopy(string $pageClass): string
    {
        $form = Livewire::test($pageClass)->instance()->form;

        $copy = [];

        foreach ($form->getFlatComponents(withHidden: true) as $component) {
            foreach (['getLabel', 'getHelperText', 'getDescription', 'getPlaceholder'] as $getter) {
                if (! method_exists($component, $getter)) {
                    continue;
                }

                try {
                    $value = $component->{$getter}();
                } catch (\Throwable) {
                    continue;
                }

                if (is_string($value)) {
                    $copy[] = $value;
                } elseif ($value instanceof Htmlable) {
                    $copy[] = $value->toHtml();
                }
            }

            if ($component instanceof Radio) {
                foreach ($component->getOptions() as $option) {
                    if (is_string($option)) {
                        $copy[] = $option;
                    }
                }
                foreach ($component->getDescriptions() as $description) {
                    if (is_string($description)) {
                        $copy[] = $description;
                    }
                }
            }
        }

        return implode("\n", array_filter($copy));
    }

    private function createPartner(): TradingPartner
    {
        $base = str_pad((string) random_int(100000000000, 899999999999), 12, '0', STR_PAD_LEFT);
        $gln = $base.$this->checkDigit($base);

        return TradingPartner::query()->create([
            'name' => 'Conformance Partner '.uniqid(),
            'gln' => $gln,
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ]);
    }

    private function checkDigit(string $base12): string
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base12[$i];
            $sum += ($i % 2 === 0) ? $digit * 3 : $digit;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        $user = User::factory()->create([
            'email' => 'owner-conformance-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function completeGoLiveChecklist(OutboundConnection $connection, User $owner): void
    {
        $settings = (array) $connection->settings;
        $settings['last_probe_ok'] = true;
        $settings['last_probe_at'] = now()->toIso8601String();
        $connection->forceFill(['settings' => $settings])->save();

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'ladder-test.xml',
            'payload_disk' => 'local',
            'payload_path' => 'tests/ladder-'.Str::random(6).'.xml',
            'file_sha256' => hash('sha256', 'ladder'),
            'received_at' => now(),
            'dscsa_affirm' => false,
            'status' => 'validated',
            'outbound_connection_id' => $connection->getKey(),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $checklist = ConnectionGoLiveChecklist::forConnection(
            ConnectionGoLiveChecklist::TYPE_OUTBOUND,
            (int) $connection->getKey(),
        );
        $this->checklistIds[] = (int) $checklist->getKey();
        $checklist->signOff($owner);
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            if ($tenant->profile !== TenantProfile::DrugWholesaler) {
                $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            }
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

        return $tenant;
    }

    private function generateSelfSignedCertPem(int $validDays): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $csr = openssl_csr_new(['commonName' => 'TP-410 Test Cert'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);

        $cert = openssl_csr_sign($csr, null, $key, $validDays);
        $this->assertInstanceOf(OpenSSLCertificate::class, $cert);

        $pem = '';
        $this->assertTrue(openssl_x509_export($cert, $pem));

        return $pem;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->checklistIds !== []) {
            ConnectionGoLiveChecklist::query()->whereIn('id', $this->checklistIds)->delete();
            $this->checklistIds = [];
        }

        if ($this->connectionIds !== []) {
            OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();

            // The Filament create page registers central approval requests; remove
            // them so other suites listing pending requests are not polluted.
            $tenantId = tenancy()->initialized ? (string) tenant()->getKey() : self::DEMO2_TENANT_ID;
            tenancy()->end();
            \App\Models\ConnectionApprovalRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('direction', 'outbound')
                ->whereIn('connection_id', $this->connectionIds)
                ->delete();
            $this->connectionIds = [];

            return;
        }

        tenancy()->end();
    }
}

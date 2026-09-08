<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\RegisterConnectionApprovalRequest;
use App\Actions\Integrations\RegisterEpcisHubRoute;
use App\Actions\Integrations\ReviewConnectionApprovalRequest;
use App\Enums\AdminRole;
use App\Enums\ConnectionApprovalStatus;
use App\Enums\InboundTransport;
use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\InboundConnections\Pages\EditInboundConnection;
use App\Filament\App\Resources\OutboundConnections\Pages\CreateOutboundConnection;
use App\Filament\App\Resources\OutboundConnections\Pages\EditOutboundConnection;
use App\Http\Controllers\Webhooks\EpcisInboundWebhookController;
use App\Models\Admin;
use App\Models\ConnectionApprovalRequest;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\TransmissionMdn;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Services\Epcis\OutboundConnectionResolver;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConnectionApprovalGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $outboundConnectionIds = [];

    /** @var list<int> */
    private array $inboundConnectionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    #[Test]
    public function connections_created_outside_review_paths_default_to_approved(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $outbound = OutboundConnection::query()->create([
                'name' => 'Grandfathered outbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $outbound->getKey();

            $inbound = InboundConnection::query()->create([
                'name' => 'Grandfathered inbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->inboundConnectionIds[] = (int) $inbound->getKey();

            $this->assertSame(ConnectionApprovalStatus::Approved, $outbound->fresh()->approval_status);
            $this->assertSame(ConnectionApprovalStatus::Approved, $inbound->fresh()->approval_status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_resolver_skips_pending_and_rejected_connections(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $pending = OutboundConnection::query()->create([
                'name' => 'A Pending '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
                'settings' => ['endpoint_url' => 'https://pending.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $pending->getKey();

            $approved = OutboundConnection::query()->create([
                'name' => 'Z Approved '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://approved.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $approved->getKey();

            $resolved = app(OutboundConnectionResolver::class)->resolveWithLadder(null);

            $this->assertNotNull($resolved);
            $this->assertTrue($resolved->isApproved());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function transmitter_skips_document_pinned_to_pending_connection(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Pending pinned '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $document = $this->createOutboundDocument($connection);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('skipped', $document->transmission_status);
            $this->assertStringContainsString('awaiting platform approval', (string) $document->error_message);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inbound_webhook_returns_403_for_pending_connection(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Pending inbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
            ]);
            $this->inboundConnectionIds[] = (int) $connection->getKey();

            $request = Request::create(
                uri: '/api/webhooks/epcis/'.$tenant->id.'/'.$connection->id,
                method: 'POST',
                content: '<root/>',
                server: [
                    'HTTP_X_INBOUND_TOKEN' => $connection->inbound_token,
                    'CONTENT_TYPE' => 'application/xml',
                ],
            );

            try {
                app(EpcisInboundWebhookController::class)->handle(
                    $request,
                    tenantId: $tenant->id,
                    connectionId: (int) $connection->id,
                );
                $this->fail('Pending connection must not receive webhook traffic.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
                $this->assertStringContainsString('awaiting platform approval', (string) $exception->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function hub_route_registration_is_refused_while_pending(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $original = [
            'gln' => $tenant->gln,
            'inbound_environment' => $tenant->inbound_environment,
            'hub_providers' => $tenant->hub_providers,
        ];

        $tenant->forceFill([
            'gln' => '0366159000010',
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
        ])->save();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Pending hub '.Str::random(4),
                'serialization_provider' => SerializationProvider::Systech,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
            ]);
            $this->inboundConnectionIds[] = (int) $connection->getKey();

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('approved by the platform');

            app(RegisterEpcisHubRoute::class)->register($connection);
        } finally {
            $tenant->forceFill($original)->save();
            $this->cleanup();
        }
    }

    #[Test]
    public function filament_create_marks_outbound_connection_pending_and_registers_central_request(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $name = 'Approval create '.Str::random(6);
            $partner = $this->createPartner();

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
                    'tradingPartners' => [$partner->getKey()],
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $connection = OutboundConnection::query()->where('name', $name)->firstOrFail();
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $this->assertSame(ConnectionApprovalStatus::Pending, $connection->approval_status);

            $request = ConnectionApprovalRequest::query()
                ->where('tenant_id', self::DEMO2_TENANT_ID)
                ->where('direction', ConnectionApprovalRequest::DIRECTION_OUTBOUND)
                ->where('connection_id', $connection->getKey())
                ->first();

            $this->assertNotNull($request);
            $this->requestIds[] = (int) $request->getKey();
            $this->assertSame(ConnectionApprovalStatus::Pending, $request->status);
            $this->assertSame($name, $request->connection_name);
            $this->assertSame('partner.example', $request->endpoint_host);
            $this->assertStringContainsString($partner->name, (string) $request->counterparty);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function approve_flips_tenant_connection_and_stamps_request(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Approve me '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $request = app(RegisterConnectionApprovalRequest::class)->register($connection);
            $this->requestIds[] = (int) $request->getKey();

            $admin = $this->createAdmin();

            app(ReviewConnectionApprovalRequest::class)->approve($request->fresh(), $admin);

            // TenantRunner ends tenancy after the dual-write; re-enter for assertions.
            tenancy()->initialize($tenant);
            $this->assertSame(ConnectionApprovalStatus::Approved, $connection->fresh()->approval_status);
            tenancy()->end();

            $request->refresh();
            $this->assertSame(ConnectionApprovalStatus::Approved, $request->status);
            $this->assertSame((int) $admin->getKey(), (int) $request->reviewed_by_admin_id);
            $this->assertNotNull($request->reviewed_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reject_stores_note_on_both_sides(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = InboundConnection::query()->create([
                'name' => 'Reject me '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
            ]);
            $this->inboundConnectionIds[] = (int) $connection->getKey();

            $request = app(RegisterConnectionApprovalRequest::class)->register($connection);
            $this->requestIds[] = (int) $request->getKey();

            $admin = $this->createAdmin();

            app(ReviewConnectionApprovalRequest::class)->reject($request->fresh(), $admin, 'Unrecognized counterparty.');

            // TenantRunner ends tenancy after the dual-write; re-enter for assertions.
            tenancy()->initialize($tenant);
            $connection->refresh();
            $this->assertSame(ConnectionApprovalStatus::Rejected, $connection->approval_status);
            $this->assertSame('Unrecognized counterparty.', $connection->approval_note);
            tenancy()->end();

            $request->refresh();
            $this->assertSame(ConnectionApprovalStatus::Rejected, $request->status);
            $this->assertSame('Unrecognized counterparty.', $request->review_note);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function register_resets_a_previously_reviewed_request(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $connection = OutboundConnection::query()->create([
                'name' => 'Resubmit '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $registrar = app(RegisterConnectionApprovalRequest::class);
            $first = $registrar->register($connection);
            $this->requestIds[] = (int) $first->getKey();

            $admin = $this->createAdmin();
            app(ReviewConnectionApprovalRequest::class)->reject($first->fresh(), $admin, 'Nope.');

            // TenantRunner ends tenancy after the dual-write; re-enter to resubmit.
            tenancy()->initialize($tenant);
            $second = $registrar->register($connection->fresh());

            $this->assertSame((int) $first->getKey(), (int) $second->getKey());
            $this->assertSame(ConnectionApprovalStatus::Pending, $second->status);
            $this->assertNull($second->reviewed_by_admin_id);
            $this->assertNull($second->reviewed_at);
            $this->assertNull($second->review_note);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function editing_rejected_outbound_connection_resubmits_for_review(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $partner = $this->createPartner();
            $connection = OutboundConnection::query()->create([
                'name' => 'Rejected edit '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Rejected,
                'approval_note' => 'Unrecognized counterparty.',
                'settings' => [
                    'kind' => OutboundConnectionKind::DirectPartner->value,
                    'endpoint_url' => 'https://partner.example/epcis',
                ],
            ]);
            $connection->syncPartners([$partner->getKey()]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            Livewire::test(EditOutboundConnection::class, ['record' => $connection->getKey()])
                ->fillForm([
                    'name' => $connection->name.' v2',
                    'settings' => ['endpoint_url' => 'https://partner.example/epcis-v2'],
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $connection->refresh();
            $this->assertSame(ConnectionApprovalStatus::Pending, $connection->approval_status);
            $this->assertNull($connection->approval_note);

            $request = ConnectionApprovalRequest::query()
                ->where('tenant_id', self::DEMO2_TENANT_ID)
                ->where('direction', ConnectionApprovalRequest::DIRECTION_OUTBOUND)
                ->where('connection_id', $connection->getKey())
                ->first();

            $this->assertNotNull($request);
            $this->requestIds[] = (int) $request->getKey();
            $this->assertSame(ConnectionApprovalStatus::Pending, $request->status);
            $this->assertSame('partner.example', $request->endpoint_host);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function editing_approved_outbound_endpoint_host_repends_for_review(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $partner = $this->createPartner();
            $connection = OutboundConnection::query()->create([
                'name' => 'Approved endpoint edit '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Approved,
                'settings' => [
                    'kind' => OutboundConnectionKind::DirectPartner->value,
                    'endpoint_url' => 'https://partner.example/epcis',
                ],
            ]);
            $connection->syncPartners([$partner->getKey()]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $request = app(RegisterConnectionApprovalRequest::class)->registerGrandfathered($connection);
            $this->assertNotNull($request);
            $this->requestIds[] = (int) $request->getKey();
            $this->assertSame(ConnectionApprovalStatus::Approved, $request->status);
            $this->assertSame('partner.example', $request->endpoint_host);

            Livewire::test(EditOutboundConnection::class, ['record' => $connection->getKey()])
                ->fillForm([
                    'settings' => ['endpoint_url' => 'https://new-partner.example/epcis'],
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $connection->refresh();
            $this->assertSame(ConnectionApprovalStatus::Pending, $connection->approval_status);

            $request->refresh();
            $this->assertSame(ConnectionApprovalStatus::Pending, $request->status);
            $this->assertSame('new-partner.example', $request->endpoint_host);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function editing_approved_outbound_name_only_stays_approved(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $partner = $this->createPartner();
            $connection = OutboundConnection::query()->create([
                'name' => 'Approved name edit '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Approved,
                'settings' => [
                    'kind' => OutboundConnectionKind::DirectPartner->value,
                    'endpoint_url' => 'https://partner.example/epcis',
                ],
            ]);
            $connection->syncPartners([$partner->getKey()]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $request = app(RegisterConnectionApprovalRequest::class)->registerGrandfathered($connection);
            $this->assertNotNull($request);
            $this->requestIds[] = (int) $request->getKey();

            $newName = $connection->name.' renamed';

            Livewire::test(EditOutboundConnection::class, ['record' => $connection->getKey()])
                ->fillForm([
                    'name' => $newName,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $connection->refresh();
            $this->assertSame(ConnectionApprovalStatus::Approved, $connection->approval_status);
            $this->assertSame($newName, $connection->name);

            $request->refresh();
            $this->assertSame(ConnectionApprovalStatus::Approved, $request->status);
            $this->assertSame('partner.example', $request->endpoint_host);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function editing_approved_inbound_https_partner_repends_for_review(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $partner = $this->createPartner();
            $replacement = $this->createPartner();
            $connection = InboundConnection::query()->create([
                'name' => 'Approved inbound partner edit '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Approved,
            ]);
            $this->inboundConnectionIds[] = (int) $connection->getKey();

            $registrar = app(RegisterConnectionApprovalRequest::class);
            $request = $registrar->registerGrandfathered($connection);
            $this->assertNotNull($request);
            $this->requestIds[] = (int) $request->getKey();
            $this->assertSame(ConnectionApprovalStatus::Approved, $request->status);

            $before = $registrar->securityFingerprint($connection->fresh());
            $connection->trading_partner_id = $replacement->getKey();
            $afterMutation = $registrar->securityFingerprint($connection);
            $this->assertNotSame($before, $afterMutation);
            $connection->trading_partner_id = $partner->getKey();

            Livewire::test(EditInboundConnection::class, ['record' => $connection->getKey()])
                ->fillForm([
                    'trading_partner_id' => $replacement->getKey(),
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $connection->refresh();
            $this->assertSame(ConnectionApprovalStatus::Pending, $connection->approval_status);
            $this->assertSame((int) $replacement->getKey(), (int) $connection->trading_partner_id);

            $request->refresh();
            $this->assertSame(ConnectionApprovalStatus::Pending, $request->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function editing_approved_outbound_sftp_host_fingerprint_repends_for_review(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAs($this->createOwner());

            $partner = $this->createPartner();
            $originalFingerprint = 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99';
            $updatedFingerprint = '11:22:33:44:55:66:77:88:99:aa:bb:cc:dd:ee:ff:00';
            $connection = OutboundConnection::query()->create([
                'name' => 'Approved sftp fingerprint edit '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomSftp,
                'transport' => OutboundTransport::Sftp,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Approved,
                'settings' => [
                    'kind' => OutboundConnectionKind::DirectPartner->value,
                    'host' => '8.8.8.8',
                    'port' => 22,
                    'host_fingerprint' => $originalFingerprint,
                    'outbound_path' => '/outbound/epcis',
                    'root' => '/',
                ],
                'credentials' => [
                    'username' => 'sftp-user',
                    'password' => 'sftp-pass',
                ],
            ]);
            $connection->syncPartners([$partner->getKey()]);
            $this->outboundConnectionIds[] = (int) $connection->getKey();

            $registrar = app(RegisterConnectionApprovalRequest::class);
            $request = $registrar->registerGrandfathered($connection);
            $this->assertNotNull($request);
            $this->requestIds[] = (int) $request->getKey();
            $this->assertSame(ConnectionApprovalStatus::Approved, $request->status);

            $before = $registrar->securityFingerprint($connection->fresh());
            $mutated = $connection->fresh();
            $settings = $mutated->settings;
            $settings['host_fingerprint'] = $updatedFingerprint;
            $mutated->settings = $settings;
            $this->assertNotSame($before, $registrar->securityFingerprint($mutated));

            Livewire::test(EditOutboundConnection::class, ['record' => $connection->getKey()])
                ->fillForm([
                    'settings' => [
                        'host' => '8.8.8.8',
                        'port' => 22,
                        'host_fingerprint' => $updatedFingerprint,
                        'outbound_path' => '/outbound/epcis',
                        'root' => '/',
                    ],
                    'sftp_username' => 'sftp-user',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $connection->refresh();
            $this->assertSame(ConnectionApprovalStatus::Pending, $connection->approval_status);
            $this->assertSame($updatedFingerprint, $connection->settings['host_fingerprint'] ?? null);

            $request->refresh();
            $this->assertSame(ConnectionApprovalStatus::Pending, $request->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function backfill_mirrors_existing_connections_without_clobbering_pending_requests(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $approvedOutbound = OutboundConnection::query()->create([
                'name' => 'Backfill outbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $approvedOutbound->getKey();

            $approvedInbound = InboundConnection::query()->create([
                'name' => 'Backfill inbound '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->inboundConnectionIds[] = (int) $approvedInbound->getKey();

            $pendingWithRequest = OutboundConnection::query()->create([
                'name' => 'Backfill pending '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
                'settings' => ['endpoint_url' => 'https://pending.example/epcis'],
            ]);
            $this->outboundConnectionIds[] = (int) $pendingWithRequest->getKey();

            $pendingRequest = app(RegisterConnectionApprovalRequest::class)->register($pendingWithRequest);
            $this->requestIds[] = (int) $pendingRequest->getKey();

            $strayPending = InboundConnection::query()->create([
                'name' => 'Backfill stray '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
                'approval_status' => ConnectionApprovalStatus::Pending,
            ]);
            $this->inboundConnectionIds[] = (int) $strayPending->getKey();

            tenancy()->end();

            $this->artisan('tracepharma:backfill-connection-approval-requests', [
                '--tenant' => self::DEMO2_TENANT_ID,
            ])->assertSuccessful();

            // The command registers every connection in the tenant; track them all for cleanup.
            $backfilledIds = ConnectionApprovalRequest::query()
                ->where('tenant_id', self::DEMO2_TENANT_ID)
                ->where('id', '!=', $pendingRequest->getKey())
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->requestIds = array_values(array_merge($this->requestIds, $backfilledIds));

            $outboundRow = ConnectionApprovalRequest::query()
                ->where('tenant_id', self::DEMO2_TENANT_ID)
                ->where('direction', ConnectionApprovalRequest::DIRECTION_OUTBOUND)
                ->where('connection_id', $approvedOutbound->getKey())
                ->first();

            $this->assertNotNull($outboundRow);
            $this->assertSame(ConnectionApprovalStatus::Approved, $outboundRow->status);
            $this->assertSame('partner.example', $outboundRow->endpoint_host);
            $this->assertStringContainsString('Grandfathered', (string) $outboundRow->review_note);

            $inboundRow = ConnectionApprovalRequest::query()
                ->where('tenant_id', self::DEMO2_TENANT_ID)
                ->where('direction', ConnectionApprovalRequest::DIRECTION_INBOUND)
                ->where('connection_id', $approvedInbound->getKey())
                ->first();

            $this->assertNotNull($inboundRow);
            $this->assertSame(ConnectionApprovalStatus::Approved, $inboundRow->status);

            // A live pending request must not be reset by the backfill.
            $this->assertSame(ConnectionApprovalStatus::Pending, $pendingRequest->fresh()->status);

            // A stray pending connection without a central row surfaces as pending.
            $strayRow = ConnectionApprovalRequest::query()
                ->where('tenant_id', self::DEMO2_TENANT_ID)
                ->where('direction', ConnectionApprovalRequest::DIRECTION_INBOUND)
                ->where('connection_id', $strayPending->getKey())
                ->first();

            $this->assertNotNull($strayRow);
            $this->assertSame(ConnectionApprovalStatus::Pending, $strayRow->status);
        } finally {
            $this->cleanup();
        }
    }

    private function createOutboundDocument(OutboundConnection $connection): EpcisDocument
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);
        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) Str::uuid(), $xml);

        $path = 'epcis/outbound/approval-gate-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'shipment.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => true,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now(),
            'outbound_connection_id' => $connection->getKey(),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function createPartner(): TradingPartner
    {
        $base = str_pad((string) random_int(100000000000, 899999999999), 12, '0', STR_PAD_LEFT);
        $gln = $base.$this->checkDigit($base);

        return TradingPartner::query()->create([
            'name' => 'Approval Partner '.uniqid(),
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
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create([
            'email' => 'owner-approval-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function createAdmin(): Admin
    {
        // Admins live on the central connection; step out of tenancy to create one.
        $resume = tenancy()->initialized ? tenant() : null;

        if ($resume !== null) {
            tenancy()->end();
        }

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        if ($resume !== null) {
            tenancy()->initialize($resume);
        }

        return $admin;
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

        return $tenant;
    }

    private function cleanup(): void
    {
        $hasTenantArtifacts = $this->documentIds !== []
            || $this->outboundConnectionIds !== []
            || $this->inboundConnectionIds !== [];

        if ($hasTenantArtifacts && ! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            if ($this->documentIds !== []) {
                EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
                TransmissionMdn::query()->whereIn('document_id', $this->documentIds)->delete();
                if (Schema::hasTable('epcis_events')) {
                    DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
                }
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
                $this->documentIds = [];
            }

            if ($this->outboundConnectionIds !== []) {
                OutboundConnection::query()->whereIn('id', $this->outboundConnectionIds)->delete();
                $this->outboundConnectionIds = [];
            }

            if ($this->inboundConnectionIds !== []) {
                InboundConnection::query()->whereIn('id', $this->inboundConnectionIds)->delete();
                $this->inboundConnectionIds = [];
            }

            tenancy()->end();
        }

        if ($this->requestIds !== []) {
            ConnectionApprovalRequest::query()->whereIn('id', $this->requestIds)->delete();
            $this->requestIds = [];
        }

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }
    }
}

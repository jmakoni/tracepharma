<?php

namespace Tests\Feature\Epcis;

use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisReceivedVia;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Actions\Epcis\PrepareOutboundEpcisForRetransmit;
use App\Filament\App\Resources\OutboundEpcisDocuments\Actions\RetryOutboundEpcisTransmitAction;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundEpcisDocumentResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function inbound_resource_excludes_outbound_docs_and_outbound_resource_includes_them(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $user = $this->createOwnerUser(TenantProfile::DrugWholesaler);
            $this->actingAs($user);

            $inbound = $this->makeDocument('inbound');
            $outbound = $this->makeDocument('outbound');

            $inboundIds = EpcisDocumentResource::getEloquentQuery()
                ->whereIn('id', [$inbound->getKey(), $outbound->getKey()])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertContains((int) $inbound->getKey(), $inboundIds);
            $this->assertNotContains((int) $outbound->getKey(), $inboundIds);

            $outboundIds = OutboundEpcisDocumentResource::getEloquentQuery()
                ->whereIn('id', [$inbound->getKey(), $outbound->getKey()])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertContains((int) $outbound->getKey(), $outboundIds);
            $this->assertNotContains((int) $inbound->getKey(), $outboundIds);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function pharmacy_cannot_access_outbound_epcis_resource(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            tenancy()->end();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            $tenant = Tenant::query()->findOrFail($tenant->getKey());
            $this->assertSame(TenantProfile::Pharmacy, $tenant->profile);
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsOutboundIntegrations());

            tenancy()->initialize($tenant);
            $this->assertSame(TenantProfile::Pharmacy, tenant()->profile);
            $this->assertFalse(OutboundEpcisDocumentResource::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function view_url_uses_outbound_epcis_slug(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $doc = $this->makeDocument('outbound');

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $url = OutboundEpcisDocumentResource::getUrl('view', ['record' => $doc], panel: 'app');

            $this->assertStringContainsString('outbound-epcis', $url);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function filament_view_url_routes_by_direction(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $inbound = $this->makeDocument('inbound');
            $outbound = $this->makeDocument('outbound');

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertStringContainsString('outbound-epcis', $outbound->filamentViewUrl());
            $this->assertStringContainsString('inbound-epcis', $inbound->filamentViewUrl());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function transferring_document_view_url_uses_outbound_epcis_via_helper(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $doc = $this->makeDocument('outbound', [
                'authored_kind' => EpcisAuthoredKind::Transferring,
            ]);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertStringContainsString('outbound-epcis', $doc->filamentViewUrl());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function outbound_query_filters_by_authored_kind_and_excludes_inbound(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $user = $this->createOwnerUser(TenantProfile::DrugWholesaler);
            $this->actingAs($user);

            $shipping = $this->makeDocument('outbound', [
                'authored_kind' => EpcisAuthoredKind::Shipping,
            ]);
            $inbound = $this->makeDocument('inbound');

            $ids = OutboundEpcisDocumentResource::getEloquentQuery()
                ->where('authored_kind', EpcisAuthoredKind::Shipping)
                ->whereIn('id', [$shipping->getKey(), $inbound->getKey()])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertContains((int) $shipping->getKey(), $ids);
            $this->assertNotContains((int) $inbound->getKey(), $ids);

            $outboundOnlyIds = OutboundEpcisDocumentResource::getEloquentQuery()
                ->whereIn('id', [$shipping->getKey(), $inbound->getKey()])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertContains((int) $shipping->getKey(), $outboundOnlyIds);
            $this->assertNotContains((int) $inbound->getKey(), $outboundOnlyIds);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function retry_transmit_action_invokes_transmitter_and_updates_status(): void
    {
        $tenant = $this->initializeWholesalerTenant();

        try {
            $doc = $this->makeDocument('outbound', [
                'transmission_status' => 'failed',
                'payload_path' => 'epcis/test.xml',
                'error_message' => 'Connection refused',
            ]);

            $this->app->instance(PrepareOutboundEpcisForRetransmit::class, new class($doc)
            {
                public function __construct(private EpcisDocument $doc) {}

                public function handle(EpcisDocument $document): array
                {
                    return [
                        'document' => $this->doc->fresh() ?? $this->doc,
                        'mode' => 'remint',
                        'old_uuid' => (string) $this->doc->document_uuid,
                        'new_uuid' => (string) $this->doc->document_uuid,
                        'old_filename' => $this->doc->original_filename,
                        'new_filename' => (string) $this->doc->original_filename,
                    ];
                }
            });

            $fake = new class implements OutboundEpcisTransmitter
            {
                public int $calls = 0;

                public function transmit(EpcisDocument $document, bool $forceRetransmit = false): void
                {
                    $this->calls++;
                    $document->forceFill([
                        'transmission_status' => 'sent',
                        'error_message' => null,
                        'sent_at' => now(),
                    ])->save();
                }
            };

            $this->app->instance(OutboundEpcisTransmitter::class, $fake);

            $this->assertTrue(RetryOutboundEpcisTransmitAction::visible($doc));

            $updated = RetryOutboundEpcisTransmitAction::retry($doc);

            $this->assertSame(1, $fake->calls);
            $this->assertSame('sent', $updated->transmission_status);
            $this->assertFalse(RetryOutboundEpcisTransmitAction::visible($updated));
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDocument(string $direction, array $attributes = []): EpcisDocument
    {
        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => $direction,
            'received_via' => $direction === 'inbound'
                ? EpcisReceivedVia::FilamentUpload
                : null,
            'format' => 'xml',
            'original_filename' => $direction.'-resource-test-'.Str::random(6).'.xml',
            'status' => 'parsed',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
        ], $attributes));

        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function createOwnerUser(TenantProfile $profile): User
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function initializeWholesalerTenant(): Tenant
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
        }

        $this->priorProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            tenancy()->end();
        }

        if ($this->priorProfile !== null) {
            $tenant->forceFill(['profile' => $this->priorProfile])->save();
        }

        $this->documentIds = [];
        $this->userIds = [];
        $this->priorProfile = null;
    }
}

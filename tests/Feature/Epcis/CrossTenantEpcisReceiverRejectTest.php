<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Enums\As2MdnAckMode;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Exceptions\InboundReceiverGlnRejected;
use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Http\Controllers\Webhooks\EpcisInboundWebhookController;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\InboundConnection;
use App\Models\InboundConnectionLog;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Epcis\AssertInboundReceiverGln;
use App\Support\SanctumAbilities;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CleansDemo2EpcisArtifacts;
use Tests\TestCase;

class CrossTenantEpcisReceiverRejectTest extends TestCase
{
    use CleansDemo2EpcisArtifacts;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const OTHER_DOMAIN = 'wholesaler.internal.vatengi.com';

    private static bool $demo2TenantReady = false;

    private ?string $restoredOtherGln = null;

    private bool $replacedOtherGln = false;

    #[Test]
    public function portal_and_api_reject_another_tenants_receiver_without_writing_epcs(): void
    {
        $current = $this->initializeDemo2Tenant();
        $other = $this->otherTenant();

        try {
            $foreignGln = (string) $other->gln;
            $this->assertNotSame((string) $current->gln, $foreignGln);

            $currentEpcs = Epc::query()->count();
            $currentDocuments = EpcisDocument::query()->count();
            $otherEpcs = $this->epcCount($other);
            tenancy()->initialize($current);

            $xml = $this->xmlWithReceiver($foreignGln);

            $this->portalRejects($xml);
            $this->assertSame($currentDocuments, EpcisDocument::query()->count());
            $this->assertSame($currentEpcs, Epc::query()->count());
            $this->assertSame((string) $current->getKey(), (string) tenant()->getKey());

            $user = User::factory()->create();
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            tenancy()->end();

            $response = $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/epcis/inbound',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_X-Original-Filename' => 'foreign-receiver.xml',
                ],
                $xml,
            );

            $response->assertUnprocessable()
                ->assertJsonFragment(['message' => "SBDH Receiver GLN [{$foreignGln}] does not belong to this tenant."]);

            tenancy()->initialize($current);
            $this->assertSame($currentDocuments, EpcisDocument::query()->count());
            $this->assertSame($currentEpcs, Epc::query()->count());
            $this->assertSame($otherEpcs, $this->epcCount($other));
            tenancy()->initialize($current);
            $this->assertSame((string) $current->getKey(), (string) tenant()->getKey());

            $rejectType = ExceptionType::query()->where('code', 'INBOUND_RECEIVER_REJECTED')->first()
                ?? ExceptionTypeSeeder::ensure('INBOUND_RECEIVER_REJECTED');
            $this->assertNotNull($rejectType);
            $rejectCases = ExceptionCase::query()
                ->where('exception_type_id', $rejectType->getKey())
                ->where('description', 'like', '%'.$foreignGln.'%')
                ->get();
            $this->assertNotEmpty(
                $rejectCases,
                'Foreign Receiver reject must file INBOUND_RECEIVER_REJECTED without persisting the file.',
            );
            ExceptionCase::query()->whereIn('id', $rejectCases->modelKeys())->delete();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            $this->cleanupTrackedEpcisArtifacts();
            $this->restoreOtherGln();
            tenancy()->end();
        }
    }

    #[Test]
    public function per_connection_as2_rejects_another_tenants_receiver_without_writing_epcs(): void
    {
        $current = $this->initializeDemo2Tenant();
        $other = $this->otherTenant();

        try {
            $foreignGln = (string) $other->gln;
            $connection = InboundConnection::query()->create([
                'name' => 'AS2 cross-tenant reject',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_from' => 'PARTNER-AS2',
                    'as2_to' => 'TRACEPHARMA-AS2',
                    'as2_mdn_ack_mode' => As2MdnAckMode::None->value,
                    'allow_unsigned_xml' => true,
                ],
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $currentEpcs = Epc::query()->count();
            $otherEpcs = $this->epcCount($other);
            tenancy()->initialize($current);

            $xml = $this->xmlWithReceiver($foreignGln);
            tenancy()->end();

            $response = $this->call(
                'POST',
                '/api/webhooks/as2/'.$current->id.'/'.$connection->id,
                [],
                [],
                [],
                [
                    'HTTP_AS2_FROM' => 'PARTNER-AS2',
                    'HTTP_AS2_TO' => 'TRACEPHARMA-AS2',
                    'HTTP_MESSAGE_ID' => '<cross-tenant-as2@test>',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_ACCEPT' => 'application/json',
                ],
                $xml,
            );

            $response->assertUnprocessable()
                ->assertJsonFragment(['message' => 'AS2 inbound processing failed.']);

            tenancy()->initialize($current);
            $connection->refresh();
            $this->assertStringContainsString($foreignGln, (string) $connection->last_error);
            $this->assertTrue(
                InboundConnectionLog::query()
                    ->where('inbound_connection_id', $connection->id)
                    ->where('event_type', 'receive')
                    ->where('status', 'failed')
                    ->exists(),
            );
            $this->assertSame(0, EpcisDocument::query()->where('inbound_connection_id', $connection->id)->count());
            $this->assertSame($currentEpcs, Epc::query()->count());
            $this->assertSame($otherEpcs, $this->epcCount($other));
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            $this->cleanupTrackedEpcisArtifacts();
            $this->restoreOtherGln();
            tenancy()->end();
        }
    }

    #[Test]
    public function https_webhook_rejects_another_tenants_receiver_without_writing_epcs(): void
    {
        $current = $this->initializeDemo2Tenant();
        $other = $this->otherTenant();

        try {
            $foreignGln = (string) $other->gln;
            $connection = InboundConnection::query()->create([
                'name' => 'HTTPS cross-tenant reject',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->trackInboundConnectionId((int) $connection->id);

            $currentEpcs = Epc::query()->count();
            $otherEpcs = $this->epcCount($other);
            $xml = $this->xmlWithReceiver($foreignGln);

            $request = Request::create(
                uri: '/api/webhooks/epcis/'.$current->id.'/'.$connection->id,
                method: 'POST',
                content: $xml,
                server: [
                    'HTTP_X_INBOUND_TOKEN' => $connection->inbound_token,
                    'HTTP_X_ORIGINAL_FILENAME' => 'foreign-receiver.xml',
                    'CONTENT_TYPE' => 'application/xml',
                ],
            );

            $response = app(EpcisInboundWebhookController::class)->handle(
                $request,
                tenantId: $current->id,
                connectionId: (int) $connection->id,
            );

            $this->assertSame(422, $response->getStatusCode());
            $payload = $response->getData(true);
            $this->assertSame(
                "SBDH Receiver GLN [{$foreignGln}] does not belong to this tenant.",
                $payload['message'] ?? null,
            );

            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            $this->assertTrue(
                InboundConnectionLog::query()
                    ->where('inbound_connection_id', $connection->id)
                    ->where('event_type', 'receive')
                    ->where('status', 'failed')
                    ->exists(),
            );
            $this->assertSame(0, EpcisDocument::query()->where('inbound_connection_id', $connection->id)->count());
            $this->assertSame($currentEpcs, Epc::query()->count());
            $this->assertSame($otherEpcs, $this->epcCount($other));
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            $this->cleanupTrackedEpcisArtifacts();
            $this->restoreOtherGln();
            tenancy()->end();
        }
    }

    #[Test]
    public function capture_api_rejects_another_tenants_receiver_without_writing_epcs(): void
    {
        $current = $this->initializeDemo2Tenant();
        $other = $this->otherTenant();

        try {
            $foreignGln = (string) $other->gln;
            $currentEpcs = Epc::query()->count();
            $currentDocuments = EpcisDocument::query()->count();
            $otherEpcs = $this->epcCount($other);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-capture', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            $xml = $this->xmlWithReceiver($foreignGln);
            tenancy()->end();

            $response = $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/epcis/capture',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_X-Original-Filename' => 'foreign-receiver.xml',
                ],
                $xml,
            );

            $response->assertUnprocessable()
                ->assertJsonPath('type', 'CaptureInvalid')
                ->assertJsonFragment(['message' => "SBDH Receiver GLN [{$foreignGln}] does not belong to this tenant."]);

            tenancy()->initialize($current);
            $this->assertSame($currentDocuments, EpcisDocument::query()->count());
            $this->assertSame($currentEpcs, Epc::query()->count());
            $this->assertSame($otherEpcs, $this->epcCount($other));
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            $this->cleanupTrackedEpcisArtifacts();
            $this->restoreOtherGln();
            tenancy()->end();
        }
    }

    #[Test]
    public function receiver_matching_this_tenant_org_gln_still_ingests(): void
    {
        $current = $this->initializeDemo2Tenant();

        try {
            $orgGln = (string) $current->gln;
            $this->assertMatchesRegularExpression('/^\d{13}$/', $orgGln);

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $token = $user->createToken('epcis-test', [SanctumAbilities::EPCIS_UPLOAD])->plainTextToken;
            $xml = $this->xmlWithReceiver($orgGln);
            tenancy()->end();

            $response = $this->call(
                'POST',
                'http://'.self::DEMO2_DOMAIN.'/api/v1/epcis/inbound',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$token,
                    'HTTP_X-Original-Filename' => 'own-receiver.xml',
                ],
                $xml,
            );

            $response->assertAccepted()
                ->assertJsonPath('message', 'EPCIS document accepted for processing.');

            tenancy()->initialize($current);
            $document = EpcisDocument::query()->findOrFail($response->json('document_id'));
            $this->trackEpcisDocumentId((int) $document->getKey());
            $this->assertGreaterThan(0, (int) $document->event_count);
            $this->assertGreaterThan(0, Epc::query()->count());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            $this->cleanupTrackedEpcisArtifacts();
            $this->restoreOtherGln();
            tenancy()->end();
        }
    }

    #[Test]
    public function missing_sbdh_receiver_is_rejected_without_writing_epcs(): void
    {
        $current = $this->initializeDemo2Tenant();

        try {
            $currentEpcs = Epc::query()->count();
            $currentDocuments = EpcisDocument::query()->count();

            try {
                AssertInboundReceiverGln::assertBelongsToCurrentTenant($this->xmlMissingReceiver());
                $this->fail('Missing SBDH Receiver must be rejected.');
            } catch (InboundReceiverGlnRejected $exception) {
                $this->assertStringContainsString('SBDH Receiver GLN is missing', $exception->getMessage());
            }

            $this->assertSame($currentDocuments, EpcisDocument::query()->count());
            $this->assertSame($currentEpcs, Epc::query()->count());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            tenancy()->end();
        }
    }

    #[Test]
    public function portal_upload_rejects_receiver_0300001000017_when_it_is_not_this_tenant(): void
    {
        $current = $this->initializeDemo2Tenant();

        try {
            $this->assertNotSame('0300001000017', (string) $current->gln);
            $this->assertSame(
                0,
                Site::query()->where('gln', '0300001000017')->where('is_organization_facility', true)->count(),
            );

            $documents = EpcisDocument::query()->count();
            $epcs = Epc::query()->count();

            $this->portalUpload($this->xmlWithReceiver('0300001000017'), 'foreign-0300001000017.xml', 'Upload rejected');

            $this->assertSame($documents, EpcisDocument::query()->count());
            $this->assertSame($epcs, Epc::query()->count());
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    #[Test]
    public function receiver_0300001000017_ingests_when_it_is_the_company_or_receive_site_gln(): void
    {
        $current = $this->initializeDemo2Tenant();
        $originalGln = $current->gln;
        $siteId = null;

        try {
            $this->setCompanyGln('0300001000017');

            AssertInboundReceiverGln::assertBelongsToCurrentTenant(
                $this->xmlWithReceiver('urn:epc:id:sgln:030000.100001.0'),
            );

            try {
                AssertInboundReceiverGln::assertBelongsToCurrentTenant(
                    $this->xmlWithReceiver('urn:epc:id:sgln:030000.100001.1'),
                );
                $this->fail('SGLN extension other than 0 must not match the company GLN.');
            } catch (InboundReceiverGlnRejected) {
                $this->assertTrue(true);
            }

            $this->portalUpload($this->xmlWithReceiver('0300001000017'), 'company-0300001000017.xml', 'Ingest complete');
            $companyDocument = EpcisDocument::query()->where('original_filename', 'company-0300001000017.xml')->first();
            $this->assertNotNull($companyDocument);
            $this->trackEpcisDocumentId((int) $companyDocument->getKey());
            $this->assertGreaterThan(0, (int) $companyDocument->event_count);

            $this->setCompanyGln($originalGln);

            $site = Site::query()->create([
                'name' => 'Receiver site '.str()->random(6),
                'gln' => '0300001000017',
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
            $siteId = (int) $site->getKey();

            $this->portalUpload($this->xmlWithReceiver('0300001000017'), 'site-0300001000017.xml', 'Ingest complete');
            $siteDocument = EpcisDocument::query()->where('original_filename', 'site-0300001000017.xml')->first();
            $this->assertNotNull($siteDocument);
            $this->trackEpcisDocumentId((int) $siteDocument->getKey());
            $this->assertGreaterThan(0, (int) $siteDocument->event_count);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($current);
            }
            if ($siteId !== null) {
                Site::query()->whereKey($siteId)->delete();
            }
            $this->setCompanyGln($originalGln);
            $this->cleanupTrackedEpcisArtifacts();
            tenancy()->end();
        }
    }

    private function setCompanyGln(?string $gln): void
    {
        $id = (string) (tenant()?->getKey() ?? self::DEMO2_TENANT_ID);
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Tenant::query()->whereKey($id)->update(['gln' => $gln]);
        $fresh = Tenant::query()->findOrFail($id);
        tenancy()->initialize($fresh);
    }

    private function portalUpload(string $xml, string $filename, string $notification): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(ListEpcisDocuments::class)
            ->callAction('uploadEpcis', data: [
                'file' => UploadedFile::fake()->createWithContent($filename, $xml),
            ])
            ->assertNotified($notification);
    }

    private function portalRejects(string $xml): void
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(ListEpcisDocuments::class)
            ->callAction('uploadEpcis', data: [
                'file' => UploadedFile::fake()->createWithContent('foreign-receiver.xml', $xml),
            ])
            ->assertNotified('Upload rejected');

        $this->assertSame(
            (string) self::DEMO2_TENANT_ID,
            (string) tenant()->getKey(),
            'Portal reject must not switch tenant. Guard: '.AssertInboundReceiverGln::class.'::assertBelongsToCurrentTenant',
        );
    }

    private function otherTenant(): Tenant
    {
        $other = Tenant::query()
            ->whereHas('domains', fn ($query) => $query->where('domain', self::OTHER_DOMAIN))
            ->first();

        $this->assertNotNull($other, 'Expected '.self::OTHER_DOMAIN.' so the foreign Receiver is a real tenant GLN.');

        if (! preg_match('/^\d{13}$/', (string) $other->gln)) {
            $this->restoredOtherGln = $other->gln !== null ? (string) $other->gln : null;
            $this->replacedOtherGln = true;
            $other->forceFill(['gln' => '0614141999903'])->save();
            $other = $other->fresh();
        }

        $this->assertMatchesRegularExpression('/^\d{13}$/', (string) $other->gln);

        return $other;
    }

    private function restoreOtherGln(): void
    {
        if (! $this->replacedOtherGln) {
            return;
        }

        $other = Tenant::query()
            ->whereHas('domains', fn ($query) => $query->where('domain', self::OTHER_DOMAIN))
            ->first();

        $other?->forceFill(['gln' => $this->restoredOtherGln])->save();
        $this->replacedOtherGln = false;
    }

    private function epcCount(Tenant $tenant): int
    {
        return (int) $tenant->run(fn (): int => Epc::query()->count());
    }

    private function xmlWithReceiver(string $receiverGln): string
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);

        $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);

        return str_replace('0096295000009', $receiverGln, $xml);
    }

    private function xmlMissingReceiver(): string
    {
        $xml = $this->xmlWithReceiver('0096295000009');

        return (string) preg_replace(
            '/<sbdh:Receiver>.*?<\/sbdh:Receiver>/s',
            '',
            $xml,
        );
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Organization',
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

        return $tenant->fresh();
    }
}

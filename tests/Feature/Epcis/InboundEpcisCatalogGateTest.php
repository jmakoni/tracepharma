<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\EpcisReceivedVia;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundEpcisCatalogGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function inbound_catalog_shows_upload_and_hub_only(): void
    {
        $this->initializeDemo2();
        $user = $this->createOwner();
        $this->actingAs($user);

        $upload = $this->seedInbound(EpcisReceivedVia::FilamentUpload);
        $hub = $this->seedInbound(EpcisReceivedVia::HttpsWebhookHub);
        $webhook = $this->seedInbound(EpcisReceivedVia::HttpsWebhook);
        $sftp = $this->seedInbound(EpcisReceivedVia::SftpPoll);
        $cli = $this->seedInbound(EpcisReceivedVia::Cli);

        $ids = EpcisDocumentResource::getEloquentQuery()
            ->whereIn('id', [$upload, $hub, $webhook, $sftp, $cli])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $this->assertContains($upload, $ids);
        $this->assertContains($hub, $ids);
        $this->assertContains($webhook, $ids);
        $this->assertContains($sftp, $ids);
        $this->assertNotContains($cli, $ids);

        $this->cleanup();
    }

    #[Test]
    public function untagged_receive_defaults_to_cli_and_is_hidden_from_catalog(): void
    {
        $this->initializeDemo2();
        $user = $this->createOwner();
        $this->actingAs($user);

        Storage::fake('local');
        $path = storage_path('app/catalog-gate-'.Str::random(8).'.xml');
        file_put_contents($path, $this->minimalXml());

        try {
            $document = app(ReceiveEpcisUpload::class)->handle($path, [
                'direction' => 'inbound',
                'original_filename' => 'ad-hoc-shipping-validate.xml',
                'disk' => 'local',
                'dispatch' => false,
                'sync' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame(EpcisReceivedVia::Cli, $document->received_via);
            $this->assertFalse(
                EpcisDocumentResource::getEloquentQuery()
                    ->whereKey($document->getKey())
                    ->exists(),
            );
        } finally {
            @unlink($path);
            $this->cleanup();
        }
    }

    #[Test]
    public function filament_upload_meta_is_catalog_visible(): void
    {
        $this->initializeDemo2();
        $user = $this->createOwner();
        $this->actingAs($user);

        Storage::fake('local');
        $path = storage_path('app/catalog-upload-'.Str::random(8).'.xml');
        file_put_contents($path, $this->minimalXml());

        try {
            $document = app(ReceiveEpcisUpload::class)->handle($path, [
                'direction' => 'inbound',
                'received_via' => EpcisReceivedVia::FilamentUpload,
                'original_filename' => 'partner-asn.xml',
                'disk' => 'local',
                'dispatch' => false,
                'sync' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame(EpcisReceivedVia::FilamentUpload, $document->received_via);
            $this->assertTrue(
                EpcisDocumentResource::getEloquentQuery()
                    ->whereKey($document->getKey())
                    ->exists(),
            );
        } finally {
            @unlink($path);
            $this->cleanup();
        }
    }

    private function seedInbound(EpcisReceivedVia $via): int
    {
        $doc = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'received_via' => $via,
            'format' => 'xml',
            'original_filename' => $via->value.'.xml',
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/'.$via->value.'-'.Str::random(6).'.xml',
            'file_sha256' => hash('sha256', $via->value.Str::random(8)),
            'status' => 'parsed',
            'received_at' => now(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
        ]);
        $this->documentIds[] = (int) $doc->getKey();

        return (int) $doc->getKey();
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private static bool $demo2TenantReady = false;

    private function initializeDemo2(): Tenant
    {
        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);

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

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }

    private function minimalXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-08-09T12:00:00.000Z">
  <EPCISBody><EventList></EventList></EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }
}

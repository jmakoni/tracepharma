<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\DeleteEpcisDocument;
use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeSearchEngine;
use Tests\TestCase;

class DeleteEpcisDocumentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?bool $priorJobRolesEnabled = null;

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function receive_only_user_cannot_delete_epcis_document(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->enableJobRoles($tenant);

        try {
            $document = $this->makeErrorDocument();
            $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            try {
                app(DeleteEpcisDocument::class)->handle($document);
                $this->fail('Expected DomainException');
            } catch (DomainException $e) {
                $this->assertStringContainsString('Exceptions are not authorized', $e->getMessage());
            }

            $this->assertNotNull(EpcisDocument::query()->find($document->id));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_removes_document_scoped_rows_and_keeps_shared_epc(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcis_documents'));

            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $documentId = (int) $document->getKey();
            $this->documentIds[] = $documentId;

            app(EpcisIngestionService::class)->process($document);
            $document->refresh();

            $document->forceFill(['status' => 'error', 'error_message' => 'test purge'])->save();

            $epc = Epc::query()->where('epc_uri', self::SGTIN_URI)->first();
            $this->assertNotNull($epc);
            $epcUri = (string) $epc->epc_uri;

            $eventIds = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->pluck('id');
            $this->assertNotEmpty($eventIds);

            if (Schema::hasTable('document_epcs')) {
                $this->assertGreaterThan(
                    0,
                    DB::table('document_epcs')->where('document_id', $documentId)->count(),
                );
            }

            if (Schema::hasTable('aggregation_links')) {
                $this->assertGreaterThan(
                    0,
                    DB::table('aggregation_links')
                        ->whereIn('established_by_event_id', $eventIds)
                        ->count(),
                );
            }

            app(DeleteEpcisDocument::class)->handle($document->fresh(), 'Operator purged bad file');

            $this->assertNull(EpcisDocument::query()->find($documentId));
            $this->assertSame(0, EpcisEvent::query()->where('document_id', $documentId)->count());

            if (Schema::hasTable('document_epcs')) {
                $this->assertSame(
                    0,
                    DB::table('document_epcs')->where('document_id', $documentId)->count(),
                );
            }

            if (Schema::hasTable('aggregation_links')) {
                $this->assertSame(
                    0,
                    DB::table('aggregation_links')
                        ->whereIn('established_by_event_id', $eventIds)
                        ->count(),
                );
            }

            $this->assertNotNull(Epc::query()->where('epc_uri', $epcUri)->first());

            $this->documentIds = array_values(array_filter(
                $this->documentIds,
                fn (int $id): bool => $id !== $documentId,
            ));

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_rejects_validated_status(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            app(EpcisIngestionService::class)->process($document);
            $document->refresh();

            $this->assertSame('validated', $document->status);

            try {
                app(DeleteEpcisDocument::class)->handle($document);
                $this->fail('Expected DomainException');
            } catch (DomainException $e) {
                $this->assertStringContainsString('can only be deleted from status', $e->getMessage());
                $this->assertStringContainsString('validated', $e->getMessage());
            }

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_blocks_open_receiving_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('receiving_sessions'));

            $document = $this->makeErrorDocument();

            ReceivingSession::query()->create([
                'epcis_document_id' => $document->id,
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);

            try {
                app(DeleteEpcisDocument::class)->handle($document);
                $this->fail('Expected DomainException');
            } catch (DomainException $e) {
                $this->assertStringContainsString('open or in-progress receiving session', $e->getMessage());
            }

            app(DeleteEpcisDocument::class)->handle($document->fresh(), force: true);

            $this->assertNull(EpcisDocument::query()->find($document->id));

            $this->documentIds = array_values(array_filter(
                $this->documentIds,
                fn (int $id): bool => $id !== (int) $document->id,
            ));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_removes_document_events_from_scout(): void
    {
        $this->initializeDemo2Tenant();

        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine);

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $documentId = (int) $document->getKey();
            $this->documentIds[] = $documentId;

            app(EpcisIngestionService::class)->process($document);
            $document->refresh();

            $eventIds = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $this->assertNotEmpty($eventIds);

            $document->forceFill(['status' => 'error', 'error_message' => 'test purge'])->save();

            app(DeleteEpcisDocument::class)->handle($document->fresh(), 'Operator purged bad file');

            foreach ($eventIds as $eventId) {
                $this->assertContains($eventId, $engine->removed, "Event {$eventId} must be removed from Scout on hard delete");
            }

            $this->documentIds = array_values(array_filter(
                $this->documentIds,
                fn (int $id): bool => $id !== $documentId,
            ));

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uniqueFixture(string $relativePath, string $uuidPlaceholder = '11111111-2222-3333-4444-555555555555'): array
    {
        $fixture = base_path($relativePath);
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_del_');
        $this->assertNotFalse($tmp);
        $xmlPath = $tmp.'.xml';
        rename($tmp, $xmlPath);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace($uuidPlaceholder, $uuid, $xml);
        file_put_contents($xmlPath, $xml);

        return [$xmlPath, $uuid];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeErrorDocument(array $attributes = []): EpcisDocument
    {
        $path = 'epcis/inbound/delete-test-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'bad.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'error',
            'error_message' => 'test error',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ], $attributes));

        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    private function swapSearchEngine(FakeSearchEngine $engine): void
    {
        $this->app->extend(EngineManager::class, function (EngineManager $manager) use ($engine): EngineManager {
            $manager->extend('fake-scout-probe', fn (): Engine => $engine);

            return $manager;
        });

        config(['scout.driver' => 'fake-scout-probe']);
        $this->app->forgetInstance(EngineManager::class);
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

        $settings = TenantSettings::forTenant($tenant);
        $this->priorJobRolesEnabled ??= $settings->jobRolesEnabled();
        if ($settings->jobRolesEnabled()) {
            $settings->setJobRolesEnabled(false);
            $tenant->save();
        }

        return $tenant;
    }

    private function enableJobRoles(Tenant $tenant): void
    {
        $settings = TenantSettings::forTenant($tenant);
        $this->priorJobRolesEnabled = $settings->jobRolesEnabled();
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $settings->setJobRolesEnabled(true);
        $tenant->save();
    }

    private function createUserWithRole(TenantRole $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);
        $user->refresh();

        return $user;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->priorJobRolesEnabled !== null) {
            $tenant = tenant();
            if ($tenant instanceof Tenant) {
                TenantSettings::forTenant($tenant)->setJobRolesEnabled($this->priorJobRolesEnabled);
                $tenant->save();
            }
            $this->priorJobRolesEnabled = null;
        }

        $existingIds = EpcisDocument::query()
            ->whereIn('id', $this->documentIds)
            ->pluck('id')
            ->all();

        if ($existingIds !== []) {
            $eventIds = EpcisEvent::query()->whereIn('document_id', $existingIds)->pluck('id');

            if ($eventIds->isNotEmpty()) {
                if (Schema::hasTable('aggregation_links')) {
                    DB::table('aggregation_links')->whereIn('established_by_event_id', $eventIds)->delete();
                }
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
            }

            EpcisEvent::query()->whereIn('document_id', $existingIds)->delete();

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->whereIn('document_id', $existingIds)->delete();
            }

            if (Schema::hasTable('receiving_sessions')) {
                ReceivingSession::query()->whereIn('epcis_document_id', $existingIds)->delete();
            }

            EpcisDocument::query()->whereIn('id', $existingIds)->delete();
        }

        $this->documentIds = [];

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        foreach ([self::SGTIN_URI, self::SSCC_URI] as $uri) {
            $epc = Epc::query()->where('epc_uri', $uri)->first();
            if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $epc->delete();
            }
        }

        tenancy()->end();
    }
}

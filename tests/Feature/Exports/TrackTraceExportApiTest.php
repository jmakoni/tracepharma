<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\DataExportStatus;
use App\Enums\EpcisReceivedVia;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Jobs\Exports\ProcessTrackTraceExportJob;
use App\Models\DataExport;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TrackTraceExportReadyMail;
use App\Services\Exports\TrackTraceExportQuery;
use App\Services\Exports\TrackTracePdfExporter;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\SanctumAbilities;
use App\Support\TenantAppUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TrackTraceExportApiTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<string> */
    private array $exportIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.exports.disk' => 'local',
        ]);
    }

    #[Test]
    public function post_with_document_id_returns_202_and_queues_job(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            [$document] = $this->seedDocumentWithEpcs(2);
            $user = $this->createExportUser();
            $token = $user->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiJsonPost('/api/v1/exports/track-and-trace', $token, [
                'document_id' => $document->id,
            ]);

            $response->assertAccepted()
                ->assertJsonPath('data.status', DataExportStatus::Pending->value)
                ->assertJsonStructure([
                    'data' => ['export_id', 'status', 'status_url'],
                ]);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $exportId = (string) $response->json('data.export_id');
            $this->exportIds[] = $exportId;

            $this->assertDatabaseHas('data_exports', [
                'id' => $exportId,
                'status' => DataExportStatus::Pending->value,
            ]);

            Queue::assertPushed(ProcessTrackTraceExportJob::class, function (ProcessTrackTraceExportJob $job) use ($exportId): bool {
                return $job->exportId === $exportId && $job->tenantId === self::DEMO2_TENANT_ID;
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function post_with_rules_returns_202(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            [$document, $epc] = $this->seedDocumentWithEpcs(1);
            $user = $this->createExportUser();
            $token = $user->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiJsonPost('/api/v1/exports/track-and-trace', $token, [
                'rules' => [
                    [
                        'field' => 'epc.gtin14',
                        'operator' => 'eq',
                        'value' => $epc->gtin14,
                    ],
                    [
                        'field' => 'ilmd.lot_number',
                        'operator' => 'eq',
                        'value' => 'LOT-EXPORT-A',
                        'boolean' => 'and',
                    ],
                ],
            ]);

            $response->assertAccepted();
            $this->exportIds[] = (string) $response->json('data.export_id');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function post_with_both_document_id_and_rules_returns_422(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->seedDocumentWithEpcs(1);
            $user = $this->createExportUser();
            $token = $user->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiJsonPost('/api/v1/exports/track-and-trace', $token, [
                'document_id' => $document->id,
                'rules' => [
                    ['field' => 'epc.gtin14', 'operator' => 'eq', 'value' => '00301162001162'],
                ],
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['document_id', 'rules']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function post_with_no_matching_rows_returns_422(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createExportUser();
            $token = $user->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiJsonPost('/api/v1/exports/track-and-trace', $token, [
                'rules' => [
                    ['field' => 'epc.gtin14', 'operator' => 'eq', 'value' => '09999999999999'],
                    ['field' => 'ilmd.lot_number', 'operator' => 'eq', 'value' => 'NO-SUCH-LOT', 'boolean' => 'and'],
                ],
            ]);

            $response->assertUnprocessable();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function post_with_document_id_returns_422_for_other_site_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            [$documentAtB] = $this->seedDocumentWithEpcsAtSite((int) $siteB->id, 1);
            $user = $this->createSiteRestrictedExportUser([(int) $siteA->id]);
            $token = $user->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->tenantApiJsonPost('/api/v1/exports/track-and-trace', $token, [
                'document_id' => $documentAtB->id,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['document_id']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rules_export_document_id_subquery_respects_site_access(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            [$docA, $epcA] = $this->seedDocumentWithEpcsAtSite((int) $siteA->id, 1);
            [$docB] = $this->seedDocumentWithEpcsAtSite((int) $siteB->id, 1);

            $docB->forceFill(['processed_at' => now()->addMinute()])->save();

            DB::table('document_epcs')->insert([
                'document_id' => $docB->id,
                'epc_id' => $epcA->id,
                'ingest_generation' => 1,
            ]);

            $user = $this->createSiteRestrictedExportUser([(int) $siteA->id]);
            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'requested_by_user_id' => (int) $user->id,
                'filters' => [
                    'rules' => [
                        [
                            'field' => 'epc.gtin14',
                            'operator' => 'eq',
                            'value' => $epcA->gtin14,
                        ],
                    ],
                ],
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $query = app(TrackTraceExportQuery::class);
            $this->assertSame(1, $query->countForExport($export, $user));

            $row = $query->build($export, $user)->first();
            $this->assertNotNull($row);
            $this->assertSame((int) $docA->id, (int) $row->document_id);
            $this->assertNotSame((int) $docB->id, (int) $row->document_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function signed_download_rejects_tampered_storage_path(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->seedDocumentWithEpcs(1);
            $user = $this->createExportUser();
            $this->useWritableLocalExportDisk();

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Completed,
                'requested_by_user_id' => $user->id,
                'filters' => ['document_id' => $document->id],
                'row_count' => 1,
                'storage_disk' => 'local',
                'expires_at' => now()->addDay(),
                'completed_at' => now(),
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $canonicalPath = $export->storageObjectKey();
            Storage::disk('local')->put($canonicalPath, '%PDF-1.4 test');

            $export->forceFill([
                'storage_path' => $canonicalPath,
            ])->save();

            $signedUrl = TenantAppUrl::temporarySignedRoute(
                'tenant.data-export.download',
                now()->addHour(),
                ['export' => $export->getKey()],
                tenantId: self::DEMO2_TENANT_ID,
            );

            $export->forceFill(['storage_path' => '../../../etc/passwd'])->save();

            tenancy()->end();

            $this->get($signedUrl)->assertNotFound();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function get_show_returns_download_url_when_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->seedDocumentWithEpcs(1);
            $user = $this->createExportUser();

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Completed,
                'requested_by_user_id' => $user->id,
                'filters' => ['document_id' => $document->id],
                'row_count' => 1,
                'storage_disk' => 'local',
                'storage_path' => 'exports/test/export.pdf',
                'expires_at' => now()->addDay(),
                'completed_at' => now(),
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $token = $user->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiGet('/api/v1/exports/'.$export->getKey(), $token);

            $response->assertOk()
                ->assertJsonPath('data.status', DataExportStatus::Completed->value)
                ->assertJsonPath('data.row_count', 1);

            $downloadUrl = $response->json('data.download_url');
            $this->assertNotEmpty($downloadUrl);
            $this->assertStringContainsString(self::DEMO2_DOMAIN, (string) $downloadUrl);
            $this->assertStringContainsString('/exports/', (string) $downloadUrl);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function get_show_returns_403_for_other_user(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = User::factory()->create();
            $other = User::factory()->create();
            $this->userIds[] = (int) $owner->getKey();
            $this->userIds[] = (int) $other->getKey();

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Pending,
                'requested_by_user_id' => $owner->id,
                'filters' => ['document_id' => 1],
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $token = $other->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            tenancy()->end();

            $this->tenantApiGet('/api/v1/exports/'.$export->getKey(), $token)
                ->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function get_show_returns_403_when_requestor_is_null(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = $this->createExportUser();
            $token = $user->createToken('export-test', [SanctumAbilities::EPCIS_VIEW])->plainTextToken;

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Pending,
                'requested_by_user_id' => null,
                'filters' => ['document_id' => 1],
            ]);
            $this->exportIds[] = (string) $export->getKey();

            tenancy()->end();

            $this->tenantApiGet('/api/v1/exports/'.$export->getKey(), $token)
                ->assertForbidden();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_marks_export_failed_when_requestor_is_missing(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->seedDocumentWithEpcs(1);

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Pending,
                'requested_by_user_id' => null,
                'filters' => ['document_id' => $document->id],
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $job = new ProcessTrackTraceExportJob(self::DEMO2_TENANT_ID, (string) $export->getKey());
            $job->handle(
                app(TrackTraceExportQuery::class),
                app(TrackTracePdfExporter::class),
            );

            $this->reinitializeDemo2Tenant();

            $export->refresh();
            $this->assertSame(DataExportStatus::Failed, $export->status);
            $this->assertStringContainsString('requestor', strtolower((string) $export->error_message));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_completes_export_and_writes_pdf_file(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Notification::fake();

            [$document] = $this->seedDocumentWithEpcs(2);
            $user = $this->createExportUser(['email' => 'export-user@example.test']);
            $this->useWritableLocalExportDisk();

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Pending,
                'requested_by_user_id' => $user->id,
                'filters' => ['document_id' => $document->id],
                'notify_email' => 'export-user@example.test',
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $job = new ProcessTrackTraceExportJob(self::DEMO2_TENANT_ID, (string) $export->getKey());
            $job->handle(
                app(TrackTraceExportQuery::class),
                app(TrackTracePdfExporter::class),
            );

            $this->reinitializeDemo2Tenant();
            $this->useWritableLocalExportDisk();

            $export->refresh();

            $this->assertSame(DataExportStatus::Completed, $export->status);
            $this->assertGreaterThanOrEqual(0, (int) $export->row_count);
            $this->assertNotNull($export->storage_path);
            $this->assertStringEndsWith('.pdf', (string) $export->storage_path);
            $this->assertTrue(
                Storage::disk((string) $export->storage_disk)->exists((string) $export->storage_path),
                'Missing export file on disk ['.$export->storage_disk.'] '.$export->storage_path,
            );

            Notification::assertSentTo($user, TrackTraceExportReadyMail::class);
            Notification::assertSentOnDemandTimes(TrackTraceExportReadyMail::class, 0);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_marks_export_failed_when_compliance_serial_cap_exceeded(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.exports.compliance_report_max_serials' => 1]);

            [$document] = $this->seedDocumentWithEpcs(2);
            $user = $this->createExportUser();

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Pending,
                'requested_by_user_id' => $user->id,
                'filters' => ['document_id' => $document->id],
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $job = new ProcessTrackTraceExportJob(self::DEMO2_TENANT_ID, (string) $export->getKey());
            $job->handle(
                app(TrackTraceExportQuery::class),
                app(TrackTracePdfExporter::class),
            );

            $this->reinitializeDemo2Tenant();

            $export->refresh();
            $this->assertSame(DataExportStatus::Failed, $export->status);
            $this->assertStringContainsString('serialized units', strtolower((string) $export->error_message));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_completes_export_and_persists_bell_notification(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$document] = $this->seedDocumentWithEpcs(1);
            $user = $this->createExportUser(['email' => 'bell-export@example.test']);
            $this->useWritableLocalExportDisk();

            DB::table('notifications')->delete();

            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Pending,
                'requested_by_user_id' => $user->id,
                'filters' => ['document_id' => $document->id],
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $job = new ProcessTrackTraceExportJob(self::DEMO2_TENANT_ID, (string) $export->getKey());
            $job->handle(
                app(TrackTraceExportQuery::class),
                app(TrackTracePdfExporter::class),
            );

            $this->reinitializeDemo2Tenant();

            $this->assertDatabaseHas('notifications', [
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->getKey(),
            ]);

            $notification = DB::table('notifications')
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->getKey())
                ->latest('created_at')
                ->first();

            $this->assertNotNull($notification);
            $payload = json_decode((string) $notification->data, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame((string) $export->getKey(), $payload['export_id'] ?? null);
            $actionUrl = (string) ($payload['actions'][0]['url'] ?? '');
            $this->assertNotEmpty($actionUrl);
            $this->assertStringContainsString(self::DEMO2_DOMAIN, $actionUrl);
            $this->assertStringNotContainsString('admin2.', $actionUrl);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_failed_marks_export_failed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $export = $this->createTestExport([
                'type' => 'track_and_trace',
                'status' => DataExportStatus::Processing,
                'requested_by_user_id' => null,
                'filters' => ['document_id' => 999999999],
                'started_at' => now(),
            ]);
            $this->exportIds[] = (string) $export->getKey();

            $job = new ProcessTrackTraceExportJob(self::DEMO2_TENANT_ID, (string) $export->getKey());
            $job->failed(new \RuntimeException('Simulated worker failure'));

            $this->reinitializeDemo2Tenant();

            $export->refresh();

            $this->assertSame(DataExportStatus::Failed, $export->status);
            $this->assertStringContainsString('Simulated worker failure', (string) $export->error_message);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: EpcisDocument, 1?: Epc}
     */
    private function seedDocumentWithEpcs(int $count): array
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'export-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/export-test-'.str()->uuid().'.xml',
            'dscsa_affirm' => false,
            'status' => 'parsed',
            'received_via' => EpcisReceivedVia::Api,
            'event_count' => 0,
            'epc_count' => $count,
            'received_at' => now(),
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->id;

        $firstEpc = null;

        for ($i = 0; $i < $count; $i++) {
            $serial = 'exportserial'.$i;
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.'.$serial,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => $serial,
                'ai_01_21' => '010030116200116221'.$serial,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;
            $firstEpc ??= $epc;

            EpcIlmd::query()->create([
                'epc_id' => $epc->id,
                'lot_number' => 'LOT-EXPORT-A',
                'gtin14' => '00301162001162',
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => 1,
                ]);
            }
        }

        return [$document, $firstEpc];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function tenantApiJsonPost(string $uri, ?string $token, array $payload): TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            [],
            $server,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function tenantApiGet(string $uri, ?string $token): TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call('GET', $absolute, [], [], [], $server);
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

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $tenant;
    }

    private function reinitializeDemo2Tenant(): void
    {
        tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createExportUser(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(TenantRole::Owner->value);
        $user->givePermissionTo(Permissions::SitesAccessAll);
        $this->userIds[] = (int) $user->getKey();

        return $user->fresh() ?? $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTestExport(array $attributes): DataExport
    {
        $guarded = [
            'status',
            'row_count',
            'storage_disk',
            'storage_path',
            'error_message',
            'expires_at',
            'started_at',
            'completed_at',
        ];

        $fillable = collect($attributes)->except($guarded)->all();
        $forced = collect($attributes)->only($guarded)->all();

        $export = DataExport::query()->create($fillable);

        if ($forced !== []) {
            $export->forceFill($forced)->save();
        }

        return $export->fresh() ?? $export;
    }

    private function useWritableLocalExportDisk(): void
    {
        $root = '/tmp/tracepharma-exports-'.getmypid();
        if (! is_dir($root)) {
            mkdir($root, 0777, true);
        }

        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
        }

        if ($this->exportIds !== []) {
            DataExport::query()->whereIn('id', $this->exportIds)->delete();
            $this->exportIds = [];
        }

        if ($this->userIds !== []) {
            DataExport::query()->whereIn('requested_by_user_id', $this->userIds)->delete();
        }

        if ($this->epcIds !== [] && Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->whereIn('epc_id', $this->epcIds)->delete();
        }

        if ($this->epcIds !== [] && Schema::hasTable('epc_ilmd')) {
            DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
        }

        if ($this->epcIds !== []) {
            Epc::query()->whereKey($this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereKey($this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereKey($this->siteIds)->delete();
            $this->siteIds = [];
        }

        tenancy()->end();
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Export Site A '.str()->random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Export Site B '.str()->random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->id, (int) $siteB->id];

        return [$siteA, $siteB];
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createSiteRestrictedExportUser(array $siteIds): User
    {
        $user = User::factory()->create();
        $user->assignRole(TenantRole::ReceivingTechnician->value);
        $user->syncSites($siteIds);
        $this->assertFalse($user->can(Permissions::SitesAccessAll));
        $this->userIds[] = (int) $user->getKey();

        return $user->fresh() ?? $user;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    /**
     * @return array{0: EpcisDocument, 1: Epc}
     */
    private function seedDocumentWithEpcsAtSite(int $shipToSiteId, int $count): array
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'export-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => 'epcis/inbound/export-test-'.str()->uuid().'.xml',
            'dscsa_affirm' => false,
            'status' => 'parsed',
            'received_via' => EpcisReceivedVia::Api,
            'event_count' => 0,
            'epc_count' => $count,
            'received_at' => now(),
            'processed_at' => now(),
            'ship_to_site_id' => $shipToSiteId,
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->id;

        $firstEpc = null;

        for ($i = 0; $i < $count; $i++) {
            $serial = 'exportserial'.str()->random(8).$i;
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.'.$serial,
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '00301162001162',
                'serial_number' => $serial,
                'ai_01_21' => '010030116200116221'.$serial,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;
            $firstEpc ??= $epc;

            EpcIlmd::query()->create([
                'epc_id' => $epc->id,
                'lot_number' => 'LOT-EXPORT-A',
                'gtin14' => '00301162001162',
            ]);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    'document_id' => $document->id,
                    'epc_id' => $epc->id,
                    'ingest_generation' => 1,
                ]);
            }
        }

        return [$document, $firstEpc ?? throw new \RuntimeException('Expected at least one EPC.')];
    }
}

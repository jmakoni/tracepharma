<?php

declare(strict_types=1);

namespace Tests\Feature\EpcisJobs;

use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\EpcisJobs\EnqueueEpcisJob;
use App\Actions\EpcisJobs\RequeueEpcisJob;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EpcisJobRoleAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $jobIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['tracepharma.epcis_jobs.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->jobIds !== []) {
                EpcisJob::query()->whereIn('id', $this->jobIds)->each(function (EpcisJob $job): void {
                    $job->messages()->delete();
                    $job->delete();
                });
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function receive_only_user_cannot_reprocess_inbound_document(): void
    {
        $tenant = $this->initializeDemo2WithJobRoles();
        [$document] = $this->seedInboundDocument(status: 'parsed');

        $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
        $this->actingAs($user);

        try {
            app(ReprocessEpcisDocument::class)->handle($document, sync: true);
            $this->fail('Expected reprocess to be denied for receive-only job role.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not authorized for your job role', $exception->getMessage());
        } finally {
            $this->disableJobRoles($tenant);
        }
    }

    #[Test]
    public function receive_only_user_cannot_requeue_inbound_job(): void
    {
        $tenant = $this->initializeDemo2WithJobRoles();
        [$document] = $this->seedInboundDocument();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Error,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'finished_at' => now(),
            'attempt_count' => 1,
            'error_message' => 'test failure',
        ]);
        $this->jobIds[] = (int) $job->getKey();

        $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
        $this->actingAs($user);

        try {
            app(RequeueEpcisJob::class)->handle($job->fresh() ?? $job);
            $this->fail('Expected requeue to be denied for receive-only job role.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not authorized for your job role', $exception->getMessage());
        } finally {
            $this->disableJobRoles($tenant);
        }
    }

    #[Test]
    public function exceptions_user_can_requeue_inbound_job(): void
    {
        $tenant = $this->initializeDemo2WithJobRoles();
        [$document] = $this->seedInboundDocument();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Error,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'finished_at' => now(),
            'attempt_count' => 1,
            'error_message' => 'test failure',
        ]);
        $this->jobIds[] = (int) $job->getKey();

        $user = $this->createUserWithRole(TenantRole::InboundExceptionCoordinator);
        $this->actingAs($user);

        try {
            $newJob = app(RequeueEpcisJob::class)->handle($job->fresh() ?? $job);
            $this->jobIds[] = (int) $newJob->getKey();
            $this->assertNotSame($job->receipt, $newJob->receipt);
        } finally {
            $this->disableJobRoles($tenant);
        }
    }

    #[Test]
    public function exceptions_user_can_reprocess_inbound_document(): void
    {
        $tenant = $this->initializeDemo2WithJobRoles();
        [$document] = $this->seedInboundDocument(status: 'parsed');

        $user = $this->createUserWithRole(TenantRole::InboundExceptionCoordinator);
        $this->actingAs($user);

        try {
            $updated = app(ReprocessEpcisDocument::class)->handle($document, sync: true);
            $this->assertSame(1, (int) $updated->reprocess_count);
        } finally {
            $this->disableJobRoles($tenant);
        }
    }

    #[Test]
    public function integrations_user_can_requeue_inbound_job(): void
    {
        $tenant = $this->initializeDemo2WithJobRoles();
        [$document] = $this->seedInboundDocument();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Error,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'finished_at' => now(),
            'attempt_count' => 1,
            'error_message' => 'test failure',
        ]);
        $this->jobIds[] = (int) $job->getKey();

        $user = $this->createUserWithRole(TenantRole::WmsIntegrationSpecialist);
        $this->actingAs($user);

        try {
            $newJob = app(RequeueEpcisJob::class)->handle($job->fresh() ?? $job);
            $this->jobIds[] = (int) $newJob->getKey();
            $this->assertNotSame($job->receipt, $newJob->receipt);
        } finally {
            $this->disableJobRoles($tenant);
        }
    }

    #[Test]
    public function integrations_user_can_reprocess_inbound_document(): void
    {
        $tenant = $this->initializeDemo2WithJobRoles();
        [$document] = $this->seedInboundDocument(status: 'parsed');

        $user = $this->createUserWithRole(TenantRole::WmsIntegrationSpecialist);
        $this->actingAs($user);

        try {
            $updated = app(ReprocessEpcisDocument::class)->handle($document, sync: true);
            $this->assertSame(1, (int) $updated->reprocess_count);
        } finally {
            $this->disableJobRoles($tenant);
        }
    }

    #[Test]
    public function receive_only_user_cannot_enqueue_outbound_job(): void
    {
        $tenant = $this->initializeDemo2WithJobRoles();
        $document = $this->seedOutboundDocument();

        $user = $this->createUserWithRole(TenantRole::ReceivingTechnician);
        $this->actingAs($user);

        try {
            app(EnqueueEpcisJob::class)->handle($document);
            $this->fail('Expected outbound enqueue to be denied for receive-only job role.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Integrations are not authorized', $exception->getMessage());
        } finally {
            $this->disableJobRoles($tenant);
        }
    }

    private function initializeDemo2WithJobRoles(): Tenant
    {
        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);
        if ($tenant->profile !== TenantProfile::DrugWholesaler) {
            $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
        }
        tenancy()->initialize($tenant);

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
        $tenant->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $tenant;
    }

    private function disableJobRoles(Tenant $tenant): void
    {
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
        $tenant->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createUserWithRole(TenantRole $role): User
    {
        $user = User::factory()->create([
            'email' => 'epcis-role-'.Str::lower(Str::random(12)).'@example.test',
        ]);
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }

    /**
     * @return array{0: EpcisDocument}
     */
    private function seedInboundDocument(string $status = 'received'): array
    {
        Storage::fake('local');
        $path = 'epcis/inbound/role-test-'.Str::lower(Str::random(8)).'.xml';
        Storage::disk('local')->put($path, $this->minimalInboundXml());

        $document = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => basename($path),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', 'x'),
            'status' => $status,
            'received_at' => now(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return [$document];
    }

    private function seedOutboundDocument(): EpcisDocument
    {
        Storage::fake('local');
        $path = 'epcis/outbound/role-test-'.Str::lower(Str::random(8)).'.xml';
        Storage::disk('local')->put($path, '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => basename($path),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', 'x'),
            'status' => 'generated',
            'transmission_status' => 'pending',
            'received_at' => now(),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function minimalInboundXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-08-09T12:00:00.000Z">
  <EPCISBody><EventList></EventList></EPCISBody>
</epcis:EPCISDocument>
XML;
    }
}

<?php

namespace Tests\Feature\Tracing;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Enums\TracingRequestorType;
use App\Enums\TracingRequestScope;
use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TracingRequest;
use App\Models\User;
use App\Services\Tracing\TracingRequestService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TracingRequestSiteAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $requestIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->requestIds !== []) {
                TracingRequest::query()->whereIn('id', $this->requestIds)->delete();
            }
            if ($this->caseIds !== []) {
                ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }
            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function site_restricted_user_cannot_see_other_site_or_unlinked_tracing_requests(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$siteA, $siteB] = $this->createOwnedSites();
            $typeId = $this->exceptionTypeId();

            $docA = $this->createInboundDocument((int) $siteA->id);
            $docB = $this->createInboundDocument((int) $siteB->id);

            $caseA = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'document_id' => $docA->id,
                'title' => 'Trace site A '.Str::random(4),
                'description' => 'A',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $caseB = ExceptionCase::query()->create([
                'exception_type_id' => $typeId,
                'document_id' => $docB->id,
                'title' => 'Trace site B '.Str::random(4),
                'description' => 'B',
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds = [(int) $caseA->id, (int) $caseB->id];

            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $owner->id;

            $restricted = User::factory()->create();
            $restricted->syncSites([(int) $siteA->id]);
            $this->userIds[] = (int) $restricted->id;

            $service = app(TracingRequestService::class);

            $reqA = $service->create([
                'title' => 'Linked A',
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00300012345678',
                'lot' => 'LOT-A',
                'exception_id' => $caseA->id,
            ], $owner);
            $reqB = $service->create([
                'title' => 'Linked B',
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00300012345678',
                'lot' => 'LOT-B',
                'exception_id' => $caseB->id,
            ], $owner);
            $unlinked = $service->create([
                'title' => 'Unlinked',
                'requestor_type' => TracingRequestorType::Internal,
                'scope' => TracingRequestScope::Lot,
                'gtin' => '00300012345678',
                'lot' => 'LOT-U',
            ], $owner);
            $this->requestIds = [(int) $reqA->id, (int) $reqB->id, (int) $unlinked->id];

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAs($restricted);
            $visibleRestricted = TracingRequestResource::getEloquentQuery()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $reqA->id, $visibleRestricted);
            $this->assertNotContains((int) $reqB->id, $visibleRestricted);
            $this->assertNotContains((int) $unlinked->id, $visibleRestricted);

            $this->actingAs($owner);
            $visibleOwner = TracingRequestResource::getEloquentQuery()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->assertContains((int) $reqA->id, $visibleOwner);
            $this->assertContains((int) $reqB->id, $visibleOwner);
            $this->assertContains((int) $unlinked->id, $visibleOwner);
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createOwnedSites(): array
    {
        $siteA = Site::factory()->owned()->create([
            'name' => 'Trace Site A '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $siteB = Site::factory()->owned()->create([
            'name' => 'Trace Site B '.Str::random(5),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
        ]);
        $this->siteIds = [(int) $siteA->id, (int) $siteB->id];

        return [$siteA, $siteB];
    }

    private function createInboundDocument(int $shipToSiteId): EpcisDocument
    {
        $document = new EpcisDocument;
        $document->forceFill([
            'document_uuid' => (string) Str::uuid(),
            'direction' => 'inbound',
            'status' => 'parsed',
            'format' => 'xml',
            'creation_date' => now(),
            'received_at' => now(),
            'ship_to_site_id' => $shipToSiteId,
            'original_filename' => 'trace-ssor-'.Str::random(6).'.xml',
            'payload_disk' => 'local',
            'payload_path' => 'tests/trace-ssor-'.Str::random(6).'.xml',
            'file_sha256' => hash('sha256', Str::random(32)),
            'ingest_generation' => 1,
        ]);
        $document->save();
        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    private function exceptionTypeId(): int
    {
        $typeId = ExceptionType::query()->value('id');
        if ($typeId !== null) {
            return (int) $typeId;
        }

        return (int) ExceptionType::query()->create([
            'code' => 'trace_site_'.Str::lower(Str::random(4)),
            'name' => 'Trace site type',
            'is_active' => true,
        ])->id;
    }

    private function uniqueGln(): string
    {
        do {
            $body = '03'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
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
}

<?php

namespace Tests\Feature\Exceptions;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Exceptions\RecheckReceiveExceptionCondition;
use App\Actions\Receiving\AuthorReceiveSessionException;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionAction;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Exceptions\InvestigatorSlaClock;
use Database\Seeders\ExceptionCaseSeeder;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class ExceptionResolutionHonestyTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function ingest_matching_epcis_clears_late_failed_epcis(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->actingAs($this->createOwnerUser());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $epc = $this->createSgtinEpc();
            $case = app(AuthorReceiveSessionException::class)->lateFailedEpcis(
                $session,
                'missing_inbound_epcis',
                auth()->user(),
                [(int) $epc->getKey()],
            );
            $this->trackCase($case);

            $this->assertSame(ExceptionStatus::New, $case->status);
            $this->assertNull($case->sla_stopped_at);

            $ingested = $this->ingestUniqueMinimalShippingFixture($epc->epc_uri);
            $this->documentId = (int) $ingested['document']->getKey();

            $fresh = $case->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame(ExceptionStatus::Cleared, $fresh->status);
            $this->assertNotNull($fresh->sla_stopped_at);
            $this->assertFalse($fresh->condition_still_true);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function recheck_still_true_leaves_sla_clock(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->actingAs($this->createOwnerUser());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $epc = $this->createSgtinEpc();
            $case = app(AuthorReceiveSessionException::class)->lateFailedEpcis(
                $session,
                'missing_inbound_epcis',
                auth()->user(),
                [(int) $epc->getKey()],
            );
            $this->trackCase($case);

            $openedAt = $case->created_at?->copy();
            $dueAt = now()->addHours(InvestigatorSlaClock::HOURS);
            $case->forceFill(['due_at' => $dueAt])->save();
            $case->refresh();

            $this->assertNotNull($openedAt);
            $this->assertNotNull($case->due_at);

            $fresh = app(RecheckReceiveExceptionCondition::class)->handle($case, auth()->user());

            $this->assertTrue($fresh->status?->isOpen());
            $this->assertSame(ExceptionStatus::New, $fresh->status);
            $this->assertTrue($fresh->condition_still_true);
            $this->assertNull($fresh->sla_stopped_at);
            $this->assertSame(
                $openedAt->toDateTimeString(),
                $fresh->created_at?->toDateTimeString(),
            );
            $this->assertSame(
                $case->due_at->toDateTimeString(),
                $fresh->due_at?->toDateTimeString(),
            );
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function manual_resolve_refused_while_product_no_data_still_true_without_override(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Notification::fake();
            $this->actingAs($this->createOwnerUser());

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $epc = $this->createSgtinEpc();
            $case = app(AuthorReceiveSessionException::class)->productNoData(
                $session,
                [(int) $epc->getKey()],
                auth()->user(),
            );
            $this->trackCase($case);

            ExceptionCaseSeeder::ensureResolutionCatalog();
            $rootCauseId = (int) ExceptionRootCause::query()->value('id');
            $actionId = (int) ExceptionAction::query()->value('id');
            $this->assertGreaterThan(0, $rootCauseId);
            $this->assertGreaterThan(0, $actionId);

            $coordinator = $this->createCoordinatorUser();

            try {
                app(ExceptionService::class)->resolve(
                    $case,
                    $coordinator,
                    $rootCauseId,
                    $actionId,
                    'Closing without an inbound file.',
                );
                $this->fail('Expected ValidationException while PRODUCT_NO_DATA is still true.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }

            $this->assertSame(ExceptionStatus::New, $case->fresh()?->status);
        } finally {
            $this->cleanup($tenant);
        }
    }

    /**
     * @return array{document: EpcisDocument, sscc_uri: string, sgtin_uri: string}
     */
    private function ingestUniqueMinimalShippingFixture(string $sgtinUri): array
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        do {
            $ssccUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Epc::query()->where('epc_uri', $ssccUri)->exists());

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace(
            [
                '11111111-2222-3333-4444-555555555555',
                'urn:epc:id:sscc:030116.01001227052',
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri],
            $xml,
        );
        file_put_contents($tmp, $xml);

        try {
            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }

        return [
            'document' => $document,
            'sscc_uri' => $ssccUri,
            'sgtin_uri' => $sgtinUri,
        ];
    }

    private function createSgtinEpc(): Epc
    {
        do {
            $serial = (string) random_int(10_000_000_000_000, 99_999_999_999_999);
            $uri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
        } while (Epc::query()->where('epc_uri', $uri)->exists());

        $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
        $this->epcIds[] = (int) $epc->getKey();

        return $epc;
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'honesty-owner-'.Str::uuid().'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function createCoordinatorUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'honesty-coord-'.Str::uuid().'@example.test',
        ]);
        $user->assignRole(TenantRole::InboundExceptionCoordinator->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function trackCase(ExceptionCase $case): void
    {
        $this->caseIds[] = (int) $case->getKey();
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
        $this->prepareDemo2ReceivingState();

        app(ExceptionTypeSeeder::class)->run();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->caseIds !== []) {
            QuarantineHold::query()->whereIn('exception_id', $this->caseIds)->delete();
            ExceptionActivity::query()->whereIn('exception_id', $this->caseIds)->delete();
            foreach ($this->caseIds as $caseId) {
                ExceptionCase::query()->find($caseId)?->epcs()->detach();
            }
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->sessionId !== null) {
            ReceivingScanLine::query()->where('receiving_session_id', $this->sessionId)->delete();
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        if ($this->epcIds !== []) {
            QuarantineHold::query()->whereIn('epc_id', $this->epcIds)->delete();
            ReceivingScanLine::query()->whereIn('epc_id', $this->epcIds)->delete();
            foreach ($this->epcIds as $epcId) {
                if (
                    DB::table('event_epcs')->where('epc_id', $epcId)->exists()
                    || DB::table('aggregation_links')->where('child_epc_id', $epcId)->exists()
                    || DB::table('aggregation_links')->where('parent_epc_id', $epcId)->exists()
                ) {
                    continue;
                }
                Epc::query()->whereKey($epcId)->delete();
            }
            $this->epcIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        $tenant->save();
        tenancy()->end();
    }
}

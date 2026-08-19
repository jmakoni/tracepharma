<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\FlagManualReceivingException;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\ReceivingIssues;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use Database\Seeders\ExceptionCaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceivingIssuesPageTest extends TestCase
{
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
    private array $siteIds = [];

    #[Test]
    public function page_is_visible_when_receiving_supported(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsReceiving());
            $this->assertTrue(ReceivingIssues::canAccess());
            $this->assertTrue(ReceivingIssues::shouldRegisterNavigation());
            $this->assertSame('Receiving issues', ReceivingIssues::getNavigationLabel());
            $this->assertSame('Receiving', ReceivingIssues::getNavigationGroup());
            $this->assertSame('receiving-issues', ReceivingIssues::getSlug());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function empty_state_without_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            Livewire::test(ReceivingIssues::class)
                ->assertOk()
                ->assertSee('No shipment selected')
                ->assertDontSee('Unconfirmed expected');
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function can_file_shortage_overage_and_damaged_for_completed_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            config(['tracepharma.regulatory_compliance.password_gate' => false]);

            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $this->seed(ExceptionCaseSeeder::class);

            $session = $this->completedSessionWithVariance($user);
            $this->sessionId = (int) $session->getKey();

            Livewire::withQueryParams(['session' => $session->getKey()])
                ->test(ReceivingIssues::class)
                ->assertOk()
                ->assertSet('sessionId', $session->getKey())
                ->assertSee('Report Shortage')
                ->assertSee('Report Overage')
                ->assertSee('Report Damaged')
                ->assertSee('Unconfirmed expected')
                ->assertSee('Unexpected');

            $shortageEpcId = (int) ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'expected')
                ->value('epc_id');
            $overageEpcId = (int) ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'unexpected')
                ->value('epc_id');
            $damagedEpcId = (int) ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->value('epc_id');

            $this->assertGreaterThan(0, $shortageEpcId);
            $this->assertGreaterThan(0, $overageEpcId);
            $this->assertGreaterThan(0, $damagedEpcId);

            $flag = app(FlagManualReceivingException::class);

            $shortage = $flag->execute($session, 'shortage', [
                'notes' => 'Missing tote on dock',
            ], $user);
            $this->caseIds[] = (int) $shortage->getKey();

            $this->assertSame('PARTIAL_SHIPMENT_UNDECLARED', $shortage->type?->code);
            $this->assertStringContainsString('Missing tote', (string) $shortage->description);
            $this->assertSame((int) $session->site_id, (int) $shortage->site_id);

            $overage = $flag->execute($session, 'overage', [
                'notes' => 'Extra case not on ASN',
            ], $user);
            $this->caseIds[] = (int) $overage->getKey();

            $this->assertSame('OVER_SHIPMENT', $overage->type?->code);
            $this->assertTrue(
                QuarantineHold::query()
                    ->open()
                    ->where('exception_id', $overage->getKey())
                    ->where('epc_id', $overageEpcId)
                    ->exists(),
            );
            $overageHold = QuarantineHold::query()
                ->open()
                ->where('exception_id', $overage->getKey())
                ->where('epc_id', $overageEpcId)
                ->first();
            $this->assertSame(
                (int) $session->getKey(),
                (int) ($overageHold?->meta['receiving_session_id'] ?? 0),
            );
            $this->assertFalse(
                \App\Support\Exceptions\ExceptionCorrectionProfile::for('OVER_SHIPMENT')->showsWaive(),
            );
            $this->assertFalse(
                \App\Support\Exceptions\ExceptionCorrectionProfile::showsWaiveForCase($shortage),
            );

            $damaged = $flag->execute($session, 'damaged', [
                'epc_ids' => [$damagedEpcId],
                'notes' => 'Crushed corner',
            ], $user);
            $this->caseIds[] = (int) $damaged->getKey();
            $this->epcIds[] = $damagedEpcId;

            $this->assertSame('SUSPECT_PRODUCT', $damaged->type?->code);
            $this->assertTrue(
                QuarantineHold::query()
                    ->open()
                    ->where('exception_id', $damaged->getKey())
                    ->where('epc_id', $damagedEpcId)
                    ->exists(),
            );

            try {
                $flag->execute($session, 'damaged', [
                    'epc_ids' => [$shortageEpcId],
                    'notes' => 'Expected-only EPC must be rejected',
                ], $user);
                $this->fail('Expected InvalidArgumentException for expected-status EPC as damaged.');
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'eligible')
                    || str_contains($e->getMessage(), 'confirmed or unexpected'),
                );
            }

            $foreignEpc = Epc::query()->create([
                'epc_type' => 'sgtin',
                'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.foreign'.substr((string) str()->uuid(), 0, 6),
                'gtin14' => '00301162001162',
                'serial_number' => 'foreign'.substr((string) str()->uuid(), 0, 6),
                'company_prefix' => '030116',
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $foreignEpc->getKey();

            try {
                $flag->execute($session, 'damaged', [
                    'epc_ids' => [(int) $foreignEpc->getKey()],
                    'notes' => 'Should reject foreign EPC',
                ], $user);
                $this->fail('Expected InvalidArgumentException for EPC outside the receiving session.');
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'receiving session')
                    || str_contains($e->getMessage(), 'eligible'),
                    $e->getMessage(),
                );
            }

            $component = Livewire::withQueryParams(['session' => $session->getKey()])
                ->test(ReceivingIssues::class);
            $options = $component->instance()->damagedEpcOptions();
            $this->assertArrayHasKey($damagedEpcId, $options);
            $this->assertArrayHasKey($overageEpcId, $options);
            $this->assertArrayNotHasKey($shortageEpcId, $options);

            $openCases = $component->instance()->openCasesForSession();
            $this->assertTrue($openCases->contains('id', $shortage->getKey()));
            $this->assertTrue($openCases->contains('id', $overage->getKey()));
            $this->assertTrue($openCases->contains('id', $damaged->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function session_and_flag_reject_non_completed_or_out_of_scope_sessions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            config(['tracepharma.regulatory_compliance.password_gate' => false]);

            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->seed(ExceptionCaseSeeder::class);

            $document = $this->demo2ReceivableDocument();
            $site = $this->ensureEligibleReceiveSite();
            $openSession = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => $document->getKey(),
                'trading_partner_id' => $document->trading_partner_id,
                'site_id' => $site->getKey(),
                'status' => 'open',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_by' => $user->getKey(),
                'opened_at' => now(),
            ]);
            $this->sessionId = (int) $openSession->getKey();

            Livewire::test(ReceivingIssues::class)
                ->call('selectSession', $openSession->getKey())
                ->assertSet('sessionId', null);

            Livewire::test(ReceivingIssues::class)
                ->set('sessionId', $openSession->getKey())
                ->assertSet('sessionId', $openSession->getKey());

            $this->assertNull(
                Livewire::test(ReceivingIssues::class)
                    ->set('sessionId', $openSession->getKey())
                    ->instance()
                    ->session(),
            );

            try {
                app(FlagManualReceivingException::class)->execute($openSession, 'shortage', [
                    'notes' => 'Should reject open session',
                ], $user);
                $this->fail('Expected InvalidArgumentException for non-completed session.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('completed', $e->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_cases_for_session_ignores_same_document_noise(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            config(['tracepharma.regulatory_compliance.password_gate' => false]);

            $user = $this->createOwnerUser();
            $this->actingAs($user);
            $this->seed(ExceptionCaseSeeder::class);

            $session = $this->completedSessionWithVariance($user);
            $this->sessionId = (int) $session->getKey();

            $noise = ExceptionCase::query()->create([
                'exception_type_id' => \App\Models\Exceptions\ExceptionType::query()
                    ->where('code', 'OVER_SHIPMENT')
                    ->value('id'),
                'document_id' => $session->epcis_document_id,
                'trading_partner_id' => $session->trading_partner_id,
                'title' => 'Unrelated document signal',
                'description' => 'Should not appear on receiving issues list',
                'severity' => \App\Enums\ExceptionSeverity::High->value,
                'status' => \App\Enums\ExceptionStatus::New->value,
            ]);
            $this->caseIds[] = (int) $noise->getKey();

            $flagged = app(FlagManualReceivingException::class)->execute($session, 'shortage', [
                'notes' => 'Session-tagged shortage',
            ], $user);
            $this->caseIds[] = (int) $flagged->getKey();

            $openCases = Livewire::withQueryParams(['session' => $session->getKey()])
                ->test(ReceivingIssues::class)
                ->instance()
                ->openCasesForSession();

            $this->assertTrue($openCases->contains('id', $flagged->getKey()));
            $this->assertFalse($openCases->contains('id', $noise->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function scan_view_has_report_link_when_completed_but_no_claim_buttons(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $document = $this->demo2ReceivableDocument();
            $session = $this->makeCompletedSession($document, $user);
            $this->sessionId = (int) $session->getKey();

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()]);

            $component->assertSee('Report receiving issues');
            $component->assertActionVisible('reportReceivingIssues');

            $report = $component->instance()->getAction('reportReceivingIssues');
            $this->assertSame(
                ReceivingIssues::urlForSession((int) $session->getKey()),
                $report->getUrl(),
            );

            // Claim macros stay off the scan HUD — they live on Receiving Issues.
            $component->assertActionDoesNotExist('reportShortage');
            $component->assertActionDoesNotExist('reportOverage');
            $component->assertActionDoesNotExist('reportDamaged');

            $html = $component->html();
            $this->assertStringNotContainsString('Report Shortage', $html);
            $this->assertStringNotContainsString('Report Overage', $html);
            $this->assertStringNotContainsString('Report Damaged', $html);
            $this->assertStringNotContainsString("mountAction('reportShortage')", $html);
            $this->assertStringNotContainsString("mountAction('reportOverage')", $html);
            $this->assertStringNotContainsString("mountAction('reportDamaged')", $html);
        } finally {
            $this->cleanup();
        }
    }

    private function completedSessionWithVariance(User $user): ReceivingSession
    {
        $document = $this->demo2ReceivableDocument();
        $session = $this->makeCompletedSession($document, $user);

        $shortageEpc = Epc::query()->create([
            'epc_type' => 'sscc',
            'epc_uri' => 'urn:epc:id:sscc:030116.9'.substr((string) str()->uuid(), 0, 8),
            'sscc18' => '003011690'.substr(preg_replace('/\D/', '', (string) str()->uuid()) ?? '1', 0, 9),
            'company_prefix' => '030116',
        ]);
        $this->epcIds[] = (int) $shortageEpc->getKey();

        $overageEpc = Epc::query()->create([
            'epc_type' => 'sscc',
            'epc_uri' => 'urn:epc:id:sscc:030116.8'.substr((string) str()->uuid(), 0, 8),
            'sscc18' => '003011680'.substr(preg_replace('/\D/', '', (string) str()->uuid()) ?? '2', 0, 9),
            'company_prefix' => '030116',
        ]);
        $this->epcIds[] = (int) $overageEpc->getKey();

        $confirmedEpc = Epc::query()->create([
            'epc_type' => 'sgtin',
            'epc_uri' => 'urn:epc:id:sgtin:030116.0200116.ri'.substr((string) str()->uuid(), 0, 6),
            'gtin14' => '00301162001162',
            'serial_number' => 'ri'.substr((string) str()->uuid(), 0, 6),
            'company_prefix' => '030116',
        ]);
        $this->epcIds[] = (int) $confirmedEpc->getKey();

        ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $shortageEpc->getKey(),
            'line_role' => 'parent',
            'status' => 'expected',
        ]);
        ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $overageEpc->getKey(),
            'line_role' => 'parent',
            'status' => 'unexpected',
            'confirmed_at' => now(),
            'confirmed_by' => $user->getKey(),
            'scan_raw' => $overageEpc->sscc18,
        ]);
        ReceivingScanLine::query()->create([
            'receiving_session_id' => $session->getKey(),
            'epc_id' => $confirmedEpc->getKey(),
            'line_role' => 'child',
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirmed_by' => $user->getKey(),
            'scan_raw' => $confirmedEpc->gtin14,
        ]);

        $session->forceFill([
            'expected_parent_count' => 2,
            'confirmed_parent_count' => 0,
            'expected_child_count' => 1,
            'confirmed_child_count' => 1,
        ])->save();

        return $session->fresh() ?? $session;
    }

    private function makeCompletedSession(EpcisDocument $document, User $user): ReceivingSession
    {
        $site = $this->ensureEligibleReceiveSite();

        return ReceivingSession::query()->create([
            'session_kind' => ReceivingSessionKind::InboundAsn,
            'epcis_document_id' => $document->getKey(),
            'trading_partner_id' => $document->trading_partner_id,
            'site_id' => $site->getKey(),
            'status' => 'completed',
            'expected_parent_count' => 1,
            'confirmed_parent_count' => 1,
            'expected_child_count' => 0,
            'confirmed_child_count' => 0,
            'opened_by' => $user->getKey(),
            'opened_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
    }

    private function ensureEligibleReceiveSite(): Site
    {
        $site = EligibleReceiveSites::forOrganization()->orderBy('id')->first();

        if ($site !== null) {
            return $site;
        }

        $site = Site::factory()->owned()->create([
            'name' => 'Receiving Issues Test Site',
            'gln' => '0366159000096',
            'is_active' => true,
            'is_headquarters' => true,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return $site;
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertTrue(
            $user->can(\App\Support\Auth\Permissions::SitesAccessAll),
            'Owner must have sites.access_all for receiving-issues site checks.',
        );

        return $user;
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

        return $tenant;
    }

    private function demo2ReceivableDocument(): EpcisDocument
    {
        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);
        $status = $requireValidated ? 'validated' : 'parsed';

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'inbound',
            'status' => $status,
            'original_filename' => 'receiving-issues-fixture.xml',
            'trading_partner_id' => null,
        ]);
        $this->documentId = (int) $document->getKey();

        return $document;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->caseIds !== []) {
                QuarantineHold::query()->whereIn('exception_id', $this->caseIds)->delete();
                ExceptionActivity::query()->whereIn('exception_id', $this->caseIds)->delete();
                foreach ($this->caseIds as $caseId) {
                    $case = ExceptionCase::query()->find($caseId);
                    $case?->epcs()->detach();
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
                Epc::query()->whereIn('id', $this->epcIds)->delete();
                $this->epcIds = [];
            }

            if ($this->siteIds !== []) {
                Site::query()->whereIn('id', $this->siteIds)->delete();
                $this->siteIds = [];
            }

            tenancy()->end();
        }
    }
}

<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\ResetReceivingSessionScans;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Filament\App\Resources\ReceivingSessions\RelationManagers\ScanLinesRelationManager;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResetReceivingSessionScansTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private const UNEXPECTED_URI = 'urn:epc:id:sgtin:0614141.107346.2017';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $unexpectedEpcId = null;

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function it_resets_confirmed_and_unexpected_scans_to_expected_parents(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->assertSame('open', $session->status);
            $this->assertSame(1, $session->expected_parent_count);

            $parentResult = app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);
            $this->assertTrue($parentResult['ok']);
            $this->assertSame('parent_confirmed', $parentResult['effect']);

            $session->refresh();
            $this->assertSame('in_progress', $session->status);
            $this->assertSame(1, $session->confirmed_parent_count);
            $this->assertSame(1, $session->expected_child_count);

            $unexpected = Epc::query()->create(Epc::materializeAttributesFromUri(self::UNEXPECTED_URI));
            $this->unexpectedEpcId = (int) $unexpected->getKey();

            $unexpectedResult = app(ConfirmReceivingScan::class)->handle($session->fresh(), self::UNEXPECTED_URI);
            $this->assertFalse($unexpectedResult['ok']);
            $this->assertSame('unexpected', $unexpectedResult['effect']);
            $this->assertSame(1, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('status', 'unexpected')
                ->count());

            $reset = app(ResetReceivingSessionScans::class)->handle($session->fresh());

            $this->assertSame('open', $reset->status);
            $this->assertSame(1, $reset->expected_parent_count);
            $this->assertSame(0, $reset->confirmed_parent_count);
            $this->assertSame(0, $reset->expected_child_count);
            $this->assertSame(0, $reset->confirmed_child_count);
            $this->assertNull($reset->completed_at);

            $lines = ReceivingScanLine::query()
                ->where('receiving_session_id', $reset->id)
                ->get();

            $this->assertCount(1, $lines);
            $this->assertSame('parent', $lines->first()->line_role);
            $this->assertSame('expected', $lines->first()->status);
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $reset->id)
                ->where('status', 'unexpected')
                ->count());
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $reset->id)
                ->where('line_role', 'child')
                ->count());

            $parentEpc = Epc::query()->where('epc_uri', self::SSCC_URI)->firstOrFail();
            $this->assertSame((int) $parentEpc->id, (int) $lines->first()->epc_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_blocks_reset_when_session_is_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('already complete');

            app(ResetReceivingSessionScans::class)->handle($session->fresh());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_blocks_reset_when_receiving_events_were_generated(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);

            $session->refresh()->forceFill([
                'receiving_events_generated_at' => now(),
            ])->save();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('receiving EPCIS events were already generated');

            app(ResetReceivingSessionScans::class)->handle($session->fresh());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reset_scans_action_is_visible_after_confirm_and_hidden_when_completed(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create([
                'email' => 'reset-scans-visible-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertActionHidden('resetScans');

            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);
            $session->refresh();
            $this->assertSame('in_progress', $session->status);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertActionVisible('resetScans');

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertActionHidden('resetScans');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirm_scan_remains_ungated_while_reset_scans_requires_confirmation_when_gate_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => true]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create([
                'email' => 'reset-scans-gate-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);

            $component = Livewire::test(ViewReceivingSession::class, ['record' => $session->fresh()->getKey()]);

            $confirm = $component->instance()->confirmScanAction();
            $this->assertFalse(
                $confirm->isConfirmationRequired(),
                'Receiving Confirm scan must not require regulatory password confirmation.',
            );

            $component->assertActionVisible('resetScans');
            $reset = $component->instance()->getAction('resetScans');
            $this->assertTrue(
                $reset->isConfirmationRequired(),
                'Reset scans must require confirmation when the regulatory gate is enabled.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reset_scans_action_dispatches_scan_lines_updated_event(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => false]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create([
                'email' => 'reset-scans-dispatch-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->fresh()->getKey()])
                ->callAction('resetScans')
                ->assertHasNoActionErrors()
                ->assertDispatchedTo(ScanLinesRelationManager::class, 'receiving-scan-lines-updated');

            $session->refresh();
            $this->assertSame('open', $session->status);
            $this->assertSame(0, $session->confirmed_parent_count);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirm_scan_action_dispatches_scan_lines_updated_to_relation_manager(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create([
                'email' => 'confirm-dispatch-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->set('scan', self::SSCC_URI)
                ->callAction('confirmScan')
                ->assertHasNoActionErrors()
                ->assertDispatchedTo(ScanLinesRelationManager::class, 'receiving-scan-lines-updated');

            $session->refresh();
            $this->assertSame(1, $session->confirmed_parent_count);
            $this->assertContains($session->status, ['in_progress', 'completed']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function scan_lines_relation_manager_reloads_confirmed_row_on_refresh_event(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create([
                'email' => 'rm-refresh-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'parent')
                ->firstOrFail();

            $this->assertSame('expected', $parentLine->status);

            $component = Livewire::test(ScanLinesRelationManager::class, [
                'ownerRecord' => $session,
                'pageClass' => ViewReceivingSession::class,
            ])->call('loadTable');

            $component->assertCanSeeTableRecords([$parentLine]);
            $this->assertSame(
                'expected',
                $component->instance()->getTableRecords()->firstWhere('id', $parentLine->id)?->status,
            );

            app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);
            $parentLine->refresh();
            $this->assertSame('confirmed', $parentLine->status);

            $component->dispatch('receiving-scan-lines-updated')
                ->assertCanSeeTableRecords([$parentLine]);

            $this->assertSame(
                'confirmed',
                $component->instance()->getTableRecords()->firstWhere('id', $parentLine->id)?->status,
            );
            $component->assertSee('Confirmed');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirmed_so_far_hides_auto_confirmed_children_but_keeps_them_in_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = User::factory()->create([
                'email' => 'hide-children-'.uniqid('', true).'@example.test',
            ]);
            $this->userIds[] = (int) $user->getKey();
            $this->actingAs($user);

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'parent')
                ->firstOrFail();

            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: true,
            );
            $this->assertTrue($result['ok']);

            $session->refresh();
            $this->assertSame(1, $session->confirmed_parent_count);
            $this->assertGreaterThan(0, $session->confirmed_child_count);
            $this->assertSame(
                $session->confirmed_child_count,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->id)
                    ->where('line_role', 'child')
                    ->where('status', 'confirmed')
                    ->count(),
            );

            $component = Livewire::test(ScanLinesRelationManager::class, [
                'ownerRecord' => $session->fresh(),
                'pageClass' => ViewReceivingSession::class,
            ])->call('loadTable');

            $component->assertCanSeeTableRecords([$parentLine->fresh()]);
            $tableRecords = $component->instance()->getTableRecords();
            $this->assertTrue(
                $tableRecords->every(fn ($line): bool => $line->line_role === 'parent' || $line->status === 'unexpected'),
                'Confirmed so far must not list underlying child lines.',
            );
            $this->assertNull($tableRecords->firstWhere('line_role', 'child'));
        } finally {
            $this->cleanup();
        }
    }

    private function ingestMinimalFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
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

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                $session = ReceivingSession::query()->where('epcis_document_id', $this->documentId)->first();
                if ($session !== null && $session->receiving_epcis_document_id !== null) {
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
                ReceivingSession::query()->where('epcis_document_id', $this->documentId)->delete();
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            foreach ($this->userIds as $userId) {
                User::query()->whereKey($userId)->delete();
            }
            $this->userIds = [];

            if ($this->unexpectedEpcId !== null) {
                $epc = Epc::query()->find($this->unexpectedEpcId);
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
                $this->unexpectedEpcId = null;
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
}

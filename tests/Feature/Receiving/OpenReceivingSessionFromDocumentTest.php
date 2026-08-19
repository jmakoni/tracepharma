<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CancelReceivingSession;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\TenantSettings;
use App\Services\Quarantine\QuarantineService;
use Database\Seeders\ExceptionCaseSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenReceivingSessionFromDocumentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private const SGTIN_URI = 'urn:epc:id:sgtin:030116.0200116.10000082001560';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $caseIds = [];

    #[Test]
    public function it_opens_a_session_from_fixture_and_confirms_parent_then_child(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->prepareFixtureReceivingState();
            $this->assertTrue(Schema::hasTable('receiving_sessions'));
            $this->assertTrue(Schema::hasTable('receiving_scan_lines'));

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);
            $this->assertSame('0301160000009', $document->sender_gln);
            $this->assertSame('0096295000009', $document->receiver_gln);

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);

            $this->assertSame(1, $session->expected_parent_count);
            $this->assertSame(0, $session->expected_child_count);
            $this->assertSame('open', $session->status);
            $this->assertSame(1, ReceivingScanLine::query()->where('receiving_session_id', $session->id)->count());
            $this->assertSame(1, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'parent')
                ->where('status', 'expected')
                ->count());

            $again = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->assertSame($session->id, $again->id);

            $parentResult = app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);
            $this->assertTrue($parentResult['ok']);
            $this->assertSame('parent_confirmed', $parentResult['effect']);
            $this->assertSame(self::SSCC_URI, $parentResult['epc']?->epc_uri);

            $session->refresh();
            $this->assertSame('in_progress', $session->status);
            $this->assertSame(1, $session->confirmed_parent_count);
            $this->assertSame(1, $session->expected_child_count);
            $this->assertSame(1, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'child')
                ->where('status', 'expected')
                ->count());

            $childResult = app(ConfirmReceivingScan::class)->handle($session, self::SGTIN_URI);
            $this->assertTrue($childResult['ok']);
            $this->assertSame('child_confirmed', $childResult['effect']);

            $session->refresh();
            $this->assertSame(1, $session->confirmed_child_count);
            $this->assertSame('completed', $session->status);
            $this->assertNotNull($session->completed_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_auto_confirms_children_when_requested(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->prepareFixtureReceivingState();
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);

            $result = app(ConfirmReceivingScan::class)->handle(
                $session,
                self::SSCC_URI,
                userId: null,
                autoConfirmChildren: true,
            );

            $this->assertTrue($result['ok']);
            $this->assertSame('parent_confirmed', $result['effect']);

            $session->refresh();
            $this->assertSame(1, $session->confirmed_parent_count);
            $this->assertSame(1, $session->expected_child_count);
            $this->assertSame(1, $session->confirmed_child_count);
            $this->assertSame('completed', $session->status);
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $session->id)
                ->where('line_role', 'child')
                ->where('status', 'expected')
                ->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_rejects_scan_of_epc_under_open_quarantine_but_allows_opening_session(): void
    {
        $this->initializeDemo2Tenant();
        $this->seed(ExceptionCaseSeeder::class);

        try {
            $this->prepareFixtureReceivingState();
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);

            $child = Epc::query()->where('epc_uri', self::SGTIN_URI)->firstOrFail();
            $case = app(QuarantineService::class)->quarantineFromFindRecall(
                epcIds: [(int) $child->id],
                reason: 'Quarantine before receive scan',
            );
            $this->caseIds[] = (int) $case->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->assertSame('open', $session->status);

            $parentResult = app(ConfirmReceivingScan::class)->handle($session, self::SSCC_URI);
            $this->assertTrue($parentResult['ok']);
            $this->assertSame('parent_confirmed', $parentResult['effect']);

            $childResult = app(ConfirmReceivingScan::class)->handle($session->fresh(), self::SGTIN_URI);
            $this->assertFalse($childResult['ok']);
            $this->assertSame('quarantined', $childResult['effect']);
            $this->assertStringContainsString('quarantine', strtolower($childResult['message']));
            $this->assertSame(
                'expected',
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $session->id)
                    ->where('epc_id', $child->id)
                    ->value('status'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_reopens_a_cancelled_asn_session_with_fresh_expected_lines(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $suffix = (string) random_int(100000, 999999);
            $ssccUri = 'urn:epc:id:sscc:030116.01001227'.substr($suffix, 0, 3);
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.1000008200'.$suffix;
            $document = $this->ingestMinimalFixture($ssccUri, $sgtinUri);
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $this->assertSame('open', $session->status);

            app(CancelReceivingSession::class)->handle($session->fresh());
            $cancelled = $session->fresh();
            $this->assertSame('cancelled', $cancelled->status);
            $this->assertNotNull($cancelled->completed_at);

            $reopened = app(OpenReceivingSessionFromDocument::class)->handle($document->fresh());
            $this->assertSame((int) $session->getKey(), (int) $reopened->getKey());
            $this->assertSame('open', $reopened->status);
            $this->assertNull($reopened->completed_at);
            $this->assertSame(0, $reopened->confirmed_parent_count);
            $this->assertSame(0, $reopened->confirmed_child_count);
            $this->assertSame(1, ReceivingScanLine::query()
                ->where('receiving_session_id', $reopened->getKey())
                ->where('line_role', 'parent')
                ->where('status', 'expected')
                ->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_does_not_backfill_site_on_completed_session(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);

            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'site_id' => null,
            ])->save();

            $completedAgain = app(OpenReceivingSessionFromDocument::class)->handle($document->fresh());
            $this->assertSame('completed', $completedAgain->status);
            $this->assertNull($completedAgain->site_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_does_not_propagate_scan_first_to_completed_asn_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();

            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            ReceivingSession::query()
                ->where('session_kind', ReceivingSessionKind::ScanFirst)
                ->delete();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle();
            app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            app(CompleteReceivingSession::class)->handle($scanFirst->fresh());

            app(OpenReceivingSessionFromDocument::class)->handle($document->fresh());

            $parentLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('line_role', 'parent')
                ->first();

            $this->assertNotNull($parentLine);
            $this->assertSame('expected', $parentLine->status);
            $this->assertSame(0, $session->fresh()->confirmed_parent_count);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function confirm_inbound_asn_rejects_completed_session(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setJobRolesEnabled(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->documentId = (int) $document->getKey();

            $session = app(OpenReceivingSessionFromDocument::class)->handle($document);
            $session->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $result = app(ConfirmReceivingScan::class)->handle($session->fresh(), self::SSCC_URI);

            $this->assertFalse($result['ok']);
            $this->assertSame('This receiving session is already closed.', $result['message']);
            $this->assertSame('not_in_session', $result['effect']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_rejects_documents_that_are_not_parsed_or_validated(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'received',
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $this->expectException(InvalidArgumentException::class);
            app(OpenReceivingSessionFromDocument::class)->handle($document);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_rejects_parsed_documents_when_require_validated_is_true(): void
    {
        $this->initializeDemo2Tenant();

        config(['tracepharma.epcis.require_validated_for_receiving' => true]);

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'parsed',
                'dscsa_affirm' => true,
                'received_at' => now(),
                'processed_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('validated');
            app(OpenReceivingSessionFromDocument::class)->handle($document);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_blocks_receiving_when_ts_gate_enabled_and_dscsa_affirm_false(): void
    {
        $this->initializeDemo2Tenant();

        config(['tracepharma.epcis.enforce_ts_for_receiving' => true]);

        try {
            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'status' => 'validated',
                'dscsa_affirm' => false,
                'received_at' => now(),
                'processed_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('transaction statement');
            app(OpenReceivingSessionFromDocument::class)->handle($document);
        } finally {
            config(['tracepharma.epcis.enforce_ts_for_receiving' => false]);
            $this->cleanup();
        }
    }

    /**
     * Remove leftover receiving sessions for fixture EPCs so scan-first propagation
     * and prior ASN runs do not pollute the next test against shared demo2 state.
     *
     * @param  list<string>  $epcUris
     */
    private function prepareFixtureReceivingState(array $epcUris = [self::SSCC_URI, self::SGTIN_URI]): void
    {
        $epcIds = Epc::query()
            ->whereIn('epc_uri', $epcUris)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($epcIds === []) {
            return;
        }

        foreach ($epcIds as $epcId) {
            QuarantineHold::query()->where('epc_id', $epcId)->delete();
        }

        $sessionIds = ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->distinct()
            ->pluck('receiving_session_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($sessionIds as $sessionId) {
            $session = ReceivingSession::query()->find($sessionId);
            if ($session === null) {
                continue;
            }

            if ($session->receiving_epcis_document_id !== null) {
                EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
            }

            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            $session->delete();
        }
    }

    private function ingestMinimalFixture(
        string $ssccUri = self::SSCC_URI,
        string $sgtinUri = self::SGTIN_URI,
    ): EpcisDocument {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        $xml = str_replace(self::SSCC_URI, $ssccUri, $xml);
        $xml = str_replace(self::SGTIN_URI, $sgtinUri, $xml);
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
            foreach ($this->caseIds as $caseId) {
                $case = ExceptionCase::query()->find($caseId);
                if ($case === null) {
                    continue;
                }
                $case->activities()->delete();
                QuarantineHold::query()->where('exception_id', $caseId)->delete();
                $case->epcs()->detach();
                $case->delete();
            }
            $this->caseIds = [];

            if ($this->documentId !== null) {
                $session = ReceivingSession::query()->where('epcis_document_id', $this->documentId)->first();
                if ($session !== null && $session->receiving_epcis_document_id !== null) {
                    // GenerateReceivingEpcisEvents runs automatically on session completion.
                    EpcisDocument::query()->whereKey($session->receiving_epcis_document_id)->delete();
                }
                ReceivingSession::query()->where('epcis_document_id', $this->documentId)->delete();
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            foreach ([self::SGTIN_URI, self::SSCC_URI] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null) {
                    QuarantineHold::query()->where('epc_id', $epc->id)->delete();
                }
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
            }

            tenancy()->end();
        }
    }
}

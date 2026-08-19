<?php

namespace Tests\Feature\Receiving;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Receiving\CompleteReceivingSession;
use App\Actions\Receiving\ConfirmReceivingScan;
use App\Actions\Receiving\GenerateReceivingEpcisEvents;
use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OneBarcodePerActiveReceivingSessionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const SSCC_URI = 'urn:epc:id:sscc:030116.01001227052';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $receivingDocumentIds = [];

    private ?int $epcId = null;

    private ?int $sourceDocumentId = null;

    private ?bool $priorRequireTi = null;

    #[Test]
    public function confirm_on_second_open_scan_first_session_is_rejected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.EX'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $sessionA = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $sessionA->getKey();

            $first = app(ConfirmReceivingScan::class)->handle($sessionA, $uri);
            $this->assertTrue($first['ok']);

            $sessionB = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $sessionB->getKey();

            $second = app(ConfirmReceivingScan::class)->handle($sessionB, $uri);
            $this->assertFalse($second['ok']);
            $this->assertSame('double_receive', $second['effect']);
            $this->assertSame('Already confirmed on another open receive session.', $second['message']);

            $this->assertSame(
                0,
                ReceivingScanLine::query()
                    ->where('receiving_session_id', $sessionB->getKey())
                    ->where('epc_id', $epc->getKey())
                    ->count(),
            );

            $again = app(ConfirmReceivingScan::class)->handle($sessionA->fresh(), $uri);
            $this->assertTrue($again['ok']);
            $this->assertSame('already_confirmed', $again['effect']);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function confirm_rejects_epc_on_completed_session_before_receiving_epcis_is_generated(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.EX'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $sessionA = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $sessionA->getKey();

            $first = app(ConfirmReceivingScan::class)->handle($sessionA, $uri);
            $this->assertTrue($first['ok']);

            $sessionA->fresh()->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();
            $this->assertNull($sessionA->fresh()->receiving_events_generated_at);

            $sessionB = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $sessionB->getKey();

            $second = app(ConfirmReceivingScan::class)->handle($sessionB, $uri);
            $this->assertFalse($second['ok']);
            $this->assertSame('double_receive', $second['effect']);
            $this->assertSame(0, ReceivingScanLine::query()
                ->where('receiving_session_id', $sessionB->getKey())
                ->where('epc_id', $epc->getKey())
                ->count());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function after_receiving_epcis_is_generated_second_session_cannot_author_another_receipt(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $suffix = (string) random_int(10000000, 99999999);
            $uri = 'urn:epc:id:sgtin:030116.3'.substr($suffix, 0, 6).'.EX'.$suffix;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcId = (int) $epc->getKey();

            $sessionA = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $sessionA->getKey();

            $first = app(ConfirmReceivingScan::class)->handle($sessionA, $uri);
            $this->assertTrue($first['ok']);

            $completed = app(CompleteReceivingSession::class)->handle($sessionA->fresh());
            $this->assertNotNull($completed->receiving_events_generated_at);
            if ($completed->receiving_epcis_document_id !== null) {
                $this->receivingDocumentIds[] = (int) $completed->receiving_epcis_document_id;
            }

            $sessionB = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionIds[] = (int) $sessionB->getKey();

            $second = app(ConfirmReceivingScan::class)->handle($sessionB, $uri);
            if ($second['ok']) {
                $sessionB->fresh()->forceFill([
                    'status' => 'completed',
                    'completed_at' => now(),
                ])->save();

                try {
                    app(GenerateReceivingEpcisEvents::class)->handle($sessionB->fresh());
                    $this->fail('Expected a second receiving EPCIS authoring to be rejected.');
                } catch (DomainException $e) {
                    $this->assertStringContainsString('already have receiving events', $e->getMessage());
                }

                $this->assertNull($sessionB->fresh()->receiving_events_generated_at);
            } else {
                $this->assertContains($second['effect'], ['double_receive', 'not_at_receive_site']);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function scan_first_reconcile_to_asn_expected_line_still_succeeds(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            TenantSettings::forTenant($tenant)->setRequireTiForScanFirst(false);
            $tenant->save();

            $document = $this->ingestMinimalFixture();
            $this->sourceDocumentId = (int) $document->getKey();
            $siteId = $this->resolveEligibleReceiveSiteId();

            $parentEpcId = (int) Epc::query()->where('epc_uri', self::SSCC_URI)->value('id');
            $this->assertGreaterThan(0, $parentEpcId);
            $this->epcId = $parentEpcId;
            $this->releaseLeftoverReceivingSessionsForEpc($parentEpcId);

            $asnSession = app(OpenReceivingSessionFromDocument::class)->handle($document, $siteId);
            $asnSession->forceFill(['site_id' => null])->save();
            $this->sessionIds[] = (int) $asnSession->getKey();

            $scanFirst = app(OpenScanFirstReceivingSession::class)->handle($siteId);
            $this->sessionIds[] = (int) $scanFirst->getKey();

            $confirm = app(ConfirmReceivingScan::class)->handle($scanFirst, self::SSCC_URI);
            $this->assertTrue($confirm['ok']);
            $this->assertSame((int) $asnSession->getKey(), (int) $confirm['reconciled_asn_session_id']);

            $asnLine = ReceivingScanLine::query()
                ->where('receiving_session_id', $asnSession->getKey())
                ->where('epc_id', $parentEpcId)
                ->first();

            $this->assertNotNull($asnLine);
            $this->assertSame('confirmed', $asnLine->status);
        } finally {
            $this->cleanup($tenant);
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
        $uuid = (string) Str::uuid();
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

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $sites = app(EligibleReceiveSites::class)->options();

        return $sites === [] ? null : (int) array_key_first($sites);
    }

    /**
     * Shared demo2 SSCC can be left expected/confirmed on prior open (or
     * completed-but-not-authored) sessions. Exclusive leftover confirmed lines
     * would block this confirm; leftover expected ASN lines would steal reconcile.
     * Expected lines never count as exclusive — only confirmed/unexpected do.
     */
    private function releaseLeftoverReceivingSessionsForEpc(int $epcId): void
    {
        $sessionIds = ReceivingScanLine::query()
            ->where('epc_id', $epcId)
            ->whereIn('status', ['confirmed', 'unexpected', 'expected'])
            ->whereHas('session', function ($query): void {
                $query->where(function ($exclusive): void {
                    $exclusive
                        ->whereIn('status', ['open', 'in_progress'])
                        ->orWhere(function ($pendingGenerate): void {
                            $pendingGenerate
                                ->where('status', 'completed')
                                ->whereNull('receiving_events_generated_at');
                        });
                });
            })
            ->pluck('receiving_session_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($sessionIds as $sessionId) {
            ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
            ReceivingSession::query()->whereKey($sessionId)->delete();
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

        $this->priorRequireTi = TenantSettings::forTenant($tenant)->requireTiForScanFirst();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            foreach ($this->sessionIds as $sessionId) {
                $authoredId = ReceivingSession::query()->whereKey($sessionId)->value('receiving_epcis_document_id');
                if ($authoredId !== null) {
                    $this->receivingDocumentIds[] = (int) $authoredId;
                }
                ReceivingScanLine::query()->where('receiving_session_id', $sessionId)->delete();
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }
            $this->sessionIds = [];

            if ($this->receivingDocumentIds !== []) {
                $eventIds = DB::table('epcis_events')
                    ->whereIn('document_id', $this->receivingDocumentIds)
                    ->pluck('id')
                    ->all();
                if ($eventIds !== []) {
                    DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                    DB::table('epcis_events')->whereIn('id', $eventIds)->delete();
                }
                if (Schema::hasTable('document_epcs')) {
                    DB::table('document_epcs')->whereIn('document_id', $this->receivingDocumentIds)->delete();
                }
                EpcisDocument::query()->whereIn('id', $this->receivingDocumentIds)->delete();
                $this->receivingDocumentIds = [];
            }

            if ($this->sourceDocumentId !== null) {
                // Leave ingested fixtures; demo2 shared. Only clear pointer.
                $this->sourceDocumentId = null;
            }

            if ($this->epcId !== null) {
                // Do not delete shared SSCC fixture EPC used by other tests.
                $uri = Epc::query()->whereKey($this->epcId)->value('epc_uri');
                if (is_string($uri) && $uri !== self::SSCC_URI) {
                    Epc::query()->whereKey($this->epcId)->delete();
                }
                $this->epcId = null;
            }

            if ($this->priorRequireTi !== null) {
                TenantSettings::forTenant($tenant)->setRequireTiForScanFirst($this->priorRequireTi);
                $tenant->save();
                $this->priorRequireTi = null;
            }

            tenancy()->end();
        }
    }
}

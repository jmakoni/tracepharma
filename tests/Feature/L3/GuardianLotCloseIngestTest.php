<?php

declare(strict_types=1);

namespace Tests\Feature\L3;

use App\Actions\L3\AuthorGuardianLotEpcisDocument;
use App\Actions\L3\ReceiveGuardianLotFeed;
use App\Enums\TenantProfile;
use App\Http\Controllers\Api\V1\GuardianLotCloseController;
use App\Jobs\L3\ConvertAndAcceptGuardianLotJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\L3\L3LotFeed;
use App\Models\L3\SerializationLot;
use App\Models\L3\SerializationLotContainerField;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\L3\GuardianDataFeedParser;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuardianLotCloseIngestTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const API_KEY = 'guardian-lot-close-test-key';

    private const SITE_GLN = '0301160000009';

    private const COMPANY_PREFIX = '030116';

    private const BOTTLE_URI = 'urn:epc:id:sgtin:030116.0200116.10000083546563';

    private const CASE_URI = 'urn:epc:id:sgtin:030116.5200116.10000009679772';

    private const PALLET_URI = 'urn:epc:id:sscc:030116.01001227967';

    private static bool $demo2TenantReady = false;

    private ?TenantProfile $priorProfile = null;

    private ?bool $priorL3Enabled = null;

    private ?string $priorL3Provider = null;

    private ?bool $priorGuardianEnabled = null;

    private ?string $priorGln = null;

    private ?string $priorCompanyPrefix = null;

    private ?bool $priorInboundEpcisKilled = null;

    private bool $captured = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $feedIds = [];

    /** @var list<int> */
    private array $lotIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function accepts_feed_projects_lot_and_epcis_and_is_idempotent_on_duplicate(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(202)->assertJsonStructure(['feed_id', 'message_id', 'status']);
            $this->assertSame('8061EE51-05B4-4F9C-BDE4-82D34601D69E', $response->json('message_id'));

            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertNotNull($feed);
            $this->assertSame('received', $feed->status);

            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 1);

            // Run the conversion job synchronously (queue is faked above).
            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);

            $feed->refresh();
            $this->assertSame('accepted', $feed->status);
            $this->assertNull($feed->error_summary);

            $lot = SerializationLot::query()->where('feed_id', $feedId)->first();
            $this->assertNotNull($lot);
            $this->lotIds[] = (int) $lot->getKey();
            $this->assertSame('608464T', $lot->lot_number);
            $this->assertSame('00301162001165', $lot->unit_gtin14);
            $this->assertSame(1, $lot->pallet_count);
            $this->assertSame(2, $lot->case_count);
            $this->assertSame(6, $lot->unit_count);
            $this->assertSame('accepted', $lot->status);
            $this->assertNotNull($lot->epcis_document_id);

            $fieldCount = SerializationLotContainerField::query()->where('lot_id', $lot->getKey())->count();
            $this->assertSame(9, $fieldCount);

            $document = EpcisDocument::query()->find($lot->epcis_document_id);
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('outbound', $document->direction);
            $this->assertSame('validated', $document->status);
            $this->assertSame('guardian_lot_close', $document->received_via?->value);

            $this->assertTrue(Epc::query()->where('epc_uri', self::BOTTLE_URI)->exists());
            $this->assertTrue(Epc::query()->where('epc_uri', self::CASE_URI)->exists());
            $this->assertTrue(Epc::query()->where('epc_uri', self::PALLET_URI)->exists());

            // Duplicate MessageID (identical body) -> same feed, no second project.
            tenancy()->end();
            $duplicateResponse = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $duplicateResponse->assertStatus(202);
            $this->assertSame($feedId, (int) $duplicateResponse->json('feed_id'));
            $this->assertSame(1, L3LotFeed::query()->count());

            // Still only one dispatch across both requests: an accepted feed is not re-queued.
            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 1);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_invalid_bearer_token(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, 'not-the-right-key');

            $response->assertStatus(401);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->assertSame(0, L3LotFeed::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_when_guardian_feature_disabled(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant, guardianEnabled: false);

        try {
            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);

            $response->assertStatus(403);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->assertSame(0, L3LotFeed::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_payload_with_doctype_declaration(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = '<!DOCTYPE DataFeed [<!ENTITY xxe SYSTEM "file:///etc/hostname">]>'.$this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);

            $response->assertStatus(422);
            $response->assertJsonFragment(['message' => 'Guardian DataFeed must not include a DOCTYPE declaration.']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_oversized_content_length_before_body_read(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.guardian_lot_close.max_upload_mb' => 1]);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $request = Request::create(
                'http://'.self::DEMO2_DOMAIN.'/api/v1/l3/guardian/lot-close',
                'POST',
                [],
                [],
                [],
                [
                    'HTTP_HOST' => self::DEMO2_DOMAIN,
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/xml',
                    'HTTP_AUTHORIZATION' => 'Bearer '.self::API_KEY,
                    'CONTENT_LENGTH' => (string) (2 * 1024 * 1024),
                ],
                '<DataFeed/>',
            );

            $response = app(GuardianLotCloseController::class)($request);

            $this->assertSame(413, $response->getStatusCode());
        } finally {
            config(['tracepharma.guardian_lot_close.max_upload_mb' => 50]);
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_missing_container_type(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = str_replace(
                '<Type>Bottle</Type>',
                '',
                $this->fixtureXml(),
                $count,
            );
            $this->assertGreaterThan(0, $count);

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);
                $this->fail('Expected missing Type to fail authoring.');
            } catch (\Throwable) {
                // expected
            }

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertSame('failed', $feed?->status);
            $this->assertStringContainsString('Type', (string) $feed?->error_summary);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_unsupported_bundle_container_type(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = str_replace(
                '<Type>Bottle</Type>',
                '<Type>Bundle</Type>',
                $this->fixtureXml(),
                $count,
            );
            $this->assertGreaterThan(0, $count);

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);
                $this->fail('Expected Bundle Type to fail authoring.');
            } catch (\Throwable) {
                // expected
            }

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertSame('failed', $feed?->status);
            $this->assertStringContainsString('unsupported', (string) $feed?->error_summary);
            $this->assertStringContainsString('Type', (string) $feed?->error_summary);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_case_qty_mismatch(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = str_replace(
                '<Data Name="CaseQty">3</Data>',
                '<Data Name="CaseQty">12</Data>',
                $this->fixtureXml(),
            );

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);
                $this->fail('Expected CaseQty mismatch to fail authoring.');
            } catch (\Throwable) {
                // expected
            }

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertSame('failed', $feed?->status);
            $this->assertStringContainsString('CaseQty', (string) $feed?->error_summary);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_when_tenant_profile_is_not_manufacturer(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant, profile: TenantProfile::Pharmacy);

        try {
            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);

            $response->assertStatus(403);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->assertSame(0, L3LotFeed::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_when_l3_provider_is_not_systech(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant, provider: null);

        try {
            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);

            $response->assertStatus(403);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->assertSame(0, L3LotFeed::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function rejects_when_inbound_epcis_kill_switch_is_active(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant, killInboundEpcis: true);

        try {
            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);

            $response->assertStatus(403);

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->assertSame(0, L3LotFeed::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Simulates a feed already archived (by an earlier ingest under a different
     * MessageID) sharing the exact same raw-payload SHA-256 as the body being
     * posted now: {@see ReceiveGuardianLotFeed} must match by
     * `file_sha256` and return the existing row rather than authoring a second
     * EPCIS document or dispatching a second conversion job.
     */
    #[Test]
    public function matches_existing_feed_by_sha256_when_message_id_differs(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);
            $documentCountBefore = EpcisDocument::query()->count();

            $existing = L3LotFeed::query()->create([
                'message_id' => 'LEGACY-MESSAGE-ID-DIFFERENT-FROM-BODY',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => 'l3/guardian/legacy-seed.xml',
                'status' => 'accepted',
            ]);
            $this->feedIds[] = (int) $existing->getKey();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(202);
            $this->assertSame((int) $existing->getKey(), (int) $response->json('feed_id'));
            $this->assertSame('LEGACY-MESSAGE-ID-DIFFERENT-FROM-BODY', $response->json('message_id'));

            $this->assertSame(1, L3LotFeed::query()->count());
            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 0);
            $this->assertSame($documentCountBefore, EpcisDocument::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * A `processing` feed stuck past the stale threshold (worker died mid-run) is
     * treated as re-dispatchable on resubmission rather than being permanently stuck.
     */
    #[Test]
    public function redispatches_a_feed_stuck_processing_past_the_stale_threshold(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);

            $stale = L3LotFeed::query()->create([
                'message_id' => '8061EE51-05B4-4F9C-BDE4-82D34601D69E',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => 'l3/guardian/stale-seed.xml',
                'status' => 'processing',
            ]);
            $this->feedIds[] = (int) $stale->getKey();
            DB::table('l3_lot_feeds')->where('id', $stale->getKey())->update(['updated_at' => now()->subSeconds(700)]);

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(202);
            $this->assertSame((int) $stale->getKey(), (int) $response->json('feed_id'));
            $this->assertSame(1, L3LotFeed::query()->count());
            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 1);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * A malformed GS1 EPC identity URI in the Details section must fail the whole
     * feed via the pre-XML hard gates ({@see AssertAuthoredObjectEventCandidate})
     * — before any EPCIS document is ever authored/persisted.
     */
    #[Test]
    public function rejects_malformed_epc_uri_before_authoring_xml(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $documentCountBefore = EpcisDocument::query()->count();

            $xml = str_replace(
                self::BOTTLE_URI,
                'urn:epc:id:sgtin:XX0116.0200116.10000083546563',
                $this->fixtureXml(),
            );
            $this->assertStringNotContainsString(self::BOTTLE_URI, $xml);

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(202);
            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 1);

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);
                $this->fail('Expected ConvertAndAcceptGuardianLotJob to throw for a malformed EPC URI.');
            } catch (\Throwable) {
                // Expected: the hard gate throws before any XML is authored.
            }

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertNotNull($feed);
            $this->assertSame('failed', $feed->status);
            $this->assertNotNull($feed->error_summary);

            $lot = SerializationLot::query()->where('feed_id', $feedId)->first();
            $this->assertNotNull($lot);
            $this->lotIds[] = (int) $lot->getKey();
            $this->assertSame('failed', $lot->status);

            $this->assertSame($documentCountBefore, EpcisDocument::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * The job re-checks tenant access / the inbound-EPCIS kill switch at the start of
     * every run (not just at receive time): if either blocks, mark failed and return
     * without throwing — a queue-level retry storm against a blocked tenant is pointless.
     */
    #[Test]
    public function job_marks_feed_failed_without_throwing_when_blocked_by_kill_switch(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $documentCountBefore = EpcisDocument::query()->count();

            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(202);
            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            $settings = TenantSettings::forTenant($tenant);
            $settings->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, true);
            $tenant->save();

            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertNotNull($feed);
            $this->assertSame('failed', $feed->status);
            $this->assertNotNull($feed->error_summary);

            $this->assertSame($documentCountBefore, EpcisDocument::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * A previously `failed` feed must actually reprocess when Guardian resubmits
     * (ReceiveGuardianLotFeed re-dispatches) — not silently no-op because `failed`
     * was treated as terminal.
     */
    #[Test]
    public function redispatched_failed_feed_reprocesses_to_accepted(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);
            $payloadPath = 'l3/guardian/failed-retry-seed.xml';
            Storage::disk('local')->put($payloadPath, $xml);

            $failed = L3LotFeed::query()->create([
                'message_id' => '8061EE51-05B4-4F9C-BDE4-82D34601D69E',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => $payloadPath,
                'status' => 'failed',
                'error_summary' => 'Simulated prior failure.',
            ]);
            $this->feedIds[] = (int) $failed->getKey();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(202);
            $this->assertSame((int) $failed->getKey(), (int) $response->json('feed_id'));
            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 1);

            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $failed->getKey()), 'handle']);

            $failed->refresh();
            $this->assertSame('accepted', $failed->status);
            $this->assertNull($failed->error_summary);

            $lot = SerializationLot::query()->where('feed_id', $failed->getKey())->first();
            $this->assertNotNull($lot);
            $this->lotIds[] = (int) $lot->getKey();
            $this->assertSame('608464T', $lot->lot_number);
            $this->assertSame('00301162001165', $lot->unit_gtin14);
            $this->assertSame('accepted', $lot->status);
            $this->assertNotNull($lot->epcis_document_id);

            $document = EpcisDocument::query()->find($lot->epcis_document_id);
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('validated', $document->status);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * An accepted lot must not be overwritten when a later feed for the same lot
     * keys fails conversion — the new feed fails, the original lot stays accepted.
     */
    #[Test]
    public function later_failed_feed_does_not_overwrite_accepted_lot(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);

            $feedA = L3LotFeed::query()->create([
                'message_id' => 'FEED-A-ACCEPTED-LOT',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => 'l3/guardian/feed-a-seed.xml',
                'status' => 'accepted',
            ]);
            $this->feedIds[] = (int) $feedA->getKey();
            Storage::disk('local')->put('l3/guardian/feed-a-seed.xml', $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'received_at' => now(),
                'direction' => 'outbound',
                'status' => 'validated',
                'received_via' => 'guardian_lot_close',
            ]);
            $this->documentIds[] = (int) $document->getKey();
            $documentCountAfterSeed = EpcisDocument::query()->count();

            $acceptedLot = SerializationLot::query()->create([
                'feed_id' => $feedA->getKey(),
                'epcis_document_id' => $document->getKey(),
                'lot_number' => '608464T',
                'unit_gtin14' => '00301162001165',
                'status' => 'accepted',
            ]);
            $this->lotIds[] = (int) $acceptedLot->getKey();

            $bundleXml = str_replace(
                '<Type>Bottle</Type>',
                '<Type>Bundle</Type>',
                $xml,
                $count,
            );
            $this->assertGreaterThan(0, $count);
            $bundleSha256 = hash('sha256', $bundleXml);
            $bundlePath = 'l3/guardian/feed-b-bundle-fail.xml';
            Storage::disk('local')->put($bundlePath, $bundleXml);

            $feedB = L3LotFeed::query()->create([
                'message_id' => 'FEED-B-BUNDLE-FAIL',
                'file_sha256' => $bundleSha256,
                'payload_disk' => 'local',
                'payload_path' => $bundlePath,
                'status' => 'received',
            ]);
            $this->feedIds[] = (int) $feedB->getKey();

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $feedB->getKey()), 'handle']);
                $this->fail('Expected Bundle Type conversion to fail.');
            } catch (\Throwable) {
                // expected
            }

            $feedB->refresh();
            $this->assertSame('failed', $feedB->status);
            $this->assertStringContainsString('cannot overwrite accepted lot', (string) $feedB->error_summary);

            $acceptedLot->refresh();
            $this->assertSame('accepted', $acceptedLot->status);
            $this->assertSame((int) $document->getKey(), (int) $acceptedLot->epcis_document_id);
            $this->assertSame((int) $feedA->getKey(), (int) $acceptedLot->feed_id);

            $this->assertSame($documentCountAfterSeed, EpcisDocument::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * The job re-checks Manufacturer / L3 / Guardian / Systech settings at run time:
     * if settings flip after receive, mark failed without projecting EPCIS.
     */
    #[Test]
    public function job_marks_feed_failed_when_l3_settings_disabled_after_receive(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $documentCountBefore = EpcisDocument::query()->count();

            $xml = $this->fixtureXml();

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(202);
            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            $settings = TenantSettings::forTenant($tenant);
            $settings->setL3Provider(null);
            $tenant->save();

            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertNotNull($feed);
            $this->assertSame('failed', $feed->status);
            $this->assertStringContainsString('Systech', (string) $feed->error_summary);

            $this->assertSame($documentCountBefore, EpcisDocument::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * C1 — A document that lands `validated` with zero persisted events must
     * never mark the lot/feed accepted (silent event_id replay skip).
     *
     * Simulated via crash-after-validate retry: first run accepts, then counts
     * are zeroed and the feed is reprocessed so duplicate-hash recovery sees a
     * validated-but-empty document.
     */
    #[Test]
    public function rejects_validated_document_with_zero_events(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);
            $payloadPath = 'l3/guardian/zero-events-seed.xml';
            Storage::disk('local')->put($payloadPath, $xml);

            $feed = L3LotFeed::query()->create([
                'message_id' => '8061EE51-05B4-4F9C-BDE4-82D34601D69E',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => $payloadPath,
                'status' => 'received',
            ]);
            $this->feedIds[] = (int) $feed->getKey();

            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $feed->getKey()), 'handle']);

            $feed->refresh();
            $this->assertSame('accepted', $feed->status);

            $lot = SerializationLot::query()->where('feed_id', $feed->getKey())->first();
            $this->assertNotNull($lot);
            $this->lotIds[] = (int) $lot->getKey();
            $this->documentIds[] = (int) $lot->epcis_document_id;

            $document = EpcisDocument::query()->findOrFail($lot->epcis_document_id);
            $document->forceFill(['event_count' => 0, 'epc_count' => 0])->save();

            $feed->forceFill(['status' => 'failed', 'error_summary' => 'Simulated crash after validate.'])->save();
            $lot->forceFill(['status' => 'processing', 'epcis_document_id' => null])->save();

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $feed->getKey()), 'handle']);
                $this->fail('Expected zero-event validated document to fail the job.');
            } catch (\Throwable) {
                // expected
            }

            $feed->refresh();
            $this->assertSame('failed', $feed->status);
            $this->assertStringContainsString('event', strtolower((string) $feed->error_summary));

            $lot->refresh();
            $this->assertSame('failed', $lot->status);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * H1 — Same MessageID with a different body must conflict (409), not return
     * the old feed / redispatch the archived XML.
     */
    #[Test]
    public function rejects_same_message_id_with_different_body(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);

            $existing = L3LotFeed::query()->create([
                'message_id' => '8061EE51-05B4-4F9C-BDE4-82D34601D69E',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => 'l3/guardian/message-id-conflict-seed.xml',
                'status' => 'accepted',
            ]);
            $this->feedIds[] = (int) $existing->getKey();

            $altered = str_replace(
                '<LotNumber>608464T</LotNumber>',
                '<LotNumber>608464T-CORRECTED</LotNumber>',
                $xml,
            );
            $this->assertNotSame($sha256, hash('sha256', $altered));

            tenancy()->end();
            $response = $this->guardianPost($altered, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(409);
            $this->assertSame(1, L3LotFeed::query()->count());
            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 0);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Finding 9 — `message_id OR file_sha256` first() can return a sha-matched
     * other feed and skip the MessageID≠SHA conflict. Lookup must be MessageID
     * first, then SHA alone.
     */
    #[Test]
    public function message_id_conflict_is_not_masked_by_sha_matched_other_feed(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $altered = str_replace(
                '<LotNumber>608464T</LotNumber>',
                '<LotNumber>608464T-CORRECTED</LotNumber>',
                $xml,
            );
            $alteredSha = hash('sha256', $altered);
            $this->assertNotSame(hash('sha256', $xml), $alteredSha);

            // Lower id: same SHA as the body we will post, but a different MessageID.
            // An OR query that prefers this row would silently skip the 409.
            $shaMatch = L3LotFeed::query()->create([
                'message_id' => 'SHA-MATCH-OTHER-MESSAGE-ID',
                'file_sha256' => $alteredSha,
                'payload_disk' => 'local',
                'payload_path' => 'l3/guardian/sha-match-other.xml',
                'status' => 'accepted',
            ]);
            $this->feedIds[] = (int) $shaMatch->getKey();

            $msgMatch = L3LotFeed::query()->create([
                'message_id' => '8061EE51-05B4-4F9C-BDE4-82D34601D69E',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => 'l3/guardian/message-id-match.xml',
                'status' => 'accepted',
            ]);
            $this->feedIds[] = (int) $msgMatch->getKey();

            tenancy()->end();
            $response = $this->guardianPost($altered, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $response->assertStatus(409);
            $this->assertSame(2, L3LotFeed::query()->count());
            Queue::assertPushed(ConvertAndAcceptGuardianLotJob::class, 0);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Finding 6 — container fields must not be wiped when conversion fails before
     * the document is accepted.
     */
    #[Test]
    public function failed_conversion_does_not_wipe_existing_container_fields(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $bundleXml = str_replace(
                '<Type>Bottle</Type>',
                '<Type>Bundle</Type>',
                $this->fixtureXml(),
                $count,
            );
            $this->assertGreaterThan(0, $count);
            $payloadPath = 'l3/guardian/container-wipe-fail.xml';
            Storage::disk('local')->put($payloadPath, $bundleXml);

            $feed = L3LotFeed::query()->create([
                'message_id' => 'CONTAINER-WIPE-FAIL-FEED',
                'file_sha256' => hash('sha256', $bundleXml),
                'payload_disk' => 'local',
                'payload_path' => $payloadPath,
                'status' => 'received',
            ]);
            $this->feedIds[] = (int) $feed->getKey();

            $lot = SerializationLot::query()->create([
                'feed_id' => $feed->getKey(),
                'lot_number' => '608464T',
                'unit_gtin14' => '00301162001165',
                'status' => 'processing',
            ]);
            $this->lotIds[] = (int) $lot->getKey();

            SerializationLotContainerField::query()->create([
                'lot_id' => $lot->getKey(),
                'epc_uri' => self::BOTTLE_URI,
                'container_type' => 'Bottle',
                'parent_epc_uri' => self::CASE_URI,
                'fields' => ['URI' => self::BOTTLE_URI],
            ]);
            $this->assertSame(1, SerializationLotContainerField::query()->where('lot_id', $lot->getKey())->count());

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $feed->getKey()), 'handle']);
                $this->fail('Expected Bundle Type conversion to fail.');
            } catch (\Throwable) {
                // expected
            }

            $feed->refresh();
            $this->assertSame('failed', $feed->status);

            $this->assertSame(
                1,
                SerializationLotContainerField::query()->where('lot_id', $lot->getKey())->count(),
                'Prior container fields must survive a failed conversion.',
            );
            $this->assertTrue(
                SerializationLotContainerField::query()
                    ->where('lot_id', $lot->getKey())
                    ->where('epc_uri', self::BOTTLE_URI)
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Finding 7 — missing UnitGTIN must fail closed so unit_gtin14 is never null
     * (MySQL unique treats NULL as distinct and would allow duplicate lot_numbers).
     */
    #[Test]
    public function rejects_missing_unit_gtin(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = str_replace(
                '<Data Name="UnitGTIN">00301162001165</Data>',
                '',
                $this->fixtureXml(),
            );
            $this->assertStringNotContainsString('UnitGTIN', $xml);

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);
                $this->fail('Expected missing UnitGTIN to fail upsert.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('UnitGTIN', $e->getMessage());
            } catch (\Throwable $e) {
                $this->assertStringContainsString('UnitGTIN', $e->getMessage());
            }

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertSame('failed', $feed?->status);
            $this->assertStringContainsString('UnitGTIN', (string) $feed?->error_summary);

            $this->assertSame(
                0,
                SerializationLot::query()->where('lot_number', '608464T')->whereNull('unit_gtin14')->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Finding 7 — invalid (non-14-digit) UnitGTIN must also fail closed.
     */
    #[Test]
    public function rejects_invalid_unit_gtin(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = str_replace(
                '<Data Name="UnitGTIN">00301162001165</Data>',
                '<Data Name="UnitGTIN">NOT-A-GTIN</Data>',
                $this->fixtureXml(),
            );

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);
                $this->fail('Expected invalid UnitGTIN to fail upsert.');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('UnitGTIN', $e->getMessage());
            }

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertSame('failed', $feed?->status);
            $this->assertStringContainsString('UnitGTIN', (string) $feed?->error_summary);

            $this->assertSame(
                0,
                SerializationLot::query()->where('lot_number', '608464T')->whereNull('unit_gtin14')->count(),
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * H2 — ShouldBeUnique window must not outlast the stale-processing redispatch
     * window, or redispatched jobs are silently dropped.
     */
    #[Test]
    public function unique_for_does_not_exceed_stale_processing_window(): void
    {
        $job = new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, 1);

        $this->assertLessThanOrEqual(
            ReceiveGuardianLotFeed::STALE_PROCESSING_SECONDS,
            $job->uniqueFor,
        );
    }

    /**
     * H3 — Crash after validate → duplicate hash on retry must attach the existing
     * validated document and mark accepted (not failed).
     */
    #[Test]
    public function recovers_accepted_on_duplicate_hash_when_existing_document_is_validated(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);
            $payloadPath = 'l3/guardian/dup-hash-recovery-seed.xml';
            Storage::disk('local')->put($payloadPath, $xml);

            $feed = L3LotFeed::query()->create([
                'message_id' => '8061EE51-05B4-4F9C-BDE4-82D34601D69E',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => $payloadPath,
                'status' => 'received',
            ]);
            $this->feedIds[] = (int) $feed->getKey();

            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $feed->getKey()), 'handle']);

            $feed->refresh();
            $this->assertSame('accepted', $feed->status);

            $lot = SerializationLot::query()->where('feed_id', $feed->getKey())->first();
            $this->assertNotNull($lot);
            $this->lotIds[] = (int) $lot->getKey();
            $existingId = (int) $lot->epcis_document_id;
            $this->documentIds[] = $existingId;
            $this->assertGreaterThan(0, (int) EpcisDocument::query()->findOrFail($existingId)->event_count);

            // Simulate crash after validate: feed/lot left non-terminal, document remains.
            $feed->forceFill(['status' => 'failed', 'error_summary' => 'Simulated crash after validate.'])->save();
            $lot->forceFill(['status' => 'processing', 'epcis_document_id' => null])->save();

            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $feed->getKey()), 'handle']);

            $feed->refresh();
            $this->assertSame('accepted', $feed->status);
            $this->assertNull($feed->error_summary);

            $lot->refresh();
            $this->assertSame('accepted', $lot->status);
            $this->assertSame($existingId, (int) $lot->epcis_document_id);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * H4 — Lot upsert must take a row lock so concurrent writers serialize on
     * (lot_number, unit_gtin14).
     */
    #[Test]
    public function upsert_lot_uses_lock_for_update(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = $this->fixtureXml();
            $sha256 = hash('sha256', $xml);
            $payloadPath = 'l3/guardian/lock-for-update-seed.xml';
            Storage::disk('local')->put($payloadPath, $xml);

            $feed = L3LotFeed::query()->create([
                'message_id' => '8061EE51-05B4-4F9C-BDE4-82D34601D69E',
                'file_sha256' => $sha256,
                'payload_disk' => 'local',
                'payload_path' => $payloadPath,
                'status' => 'received',
            ]);
            $this->feedIds[] = (int) $feed->getKey();

            $sawForUpdate = false;
            DB::listen(function ($query) use (&$sawForUpdate): void {
                if (str_contains(strtolower($query->sql), 'for update')
                    && str_contains(strtolower($query->sql), 'serialization_lots')) {
                    $sawForUpdate = true;
                }
            });

            app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, (int) $feed->getKey()), 'handle']);

            $this->assertTrue($sawForUpdate, 'Expected serialization_lots SELECT ... FOR UPDATE during upsert.');

            $feed->refresh();
            $this->assertSame('accepted', $feed->status);

            $lot = SerializationLot::query()->where('feed_id', $feed->getKey())->first();
            $this->assertNotNull($lot);
            $this->lotIds[] = (int) $lot->getKey();
            $this->documentIds[] = (int) $lot->epcis_document_id;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * H5 — Missing/non-numeric CaseQty must fail closed when Case→Bottle hierarchy exists.
     */
    #[Test]
    public function rejects_missing_case_qty_when_case_bottle_hierarchy_exists(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['tracepharma.epcis.payload_disk' => 'local']);

        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = str_replace(
                '<Data Name="CaseQty">3</Data>',
                '',
                $this->fixtureXml(),
            );
            $this->assertStringNotContainsString('CaseQty', $xml);

            tenancy()->end();
            $response = $this->guardianPost($xml, self::API_KEY);
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));

            $feedId = (int) $response->json('feed_id');
            $this->feedIds[] = $feedId;

            try {
                app()->call([new ConvertAndAcceptGuardianLotJob(self::DEMO2_TENANT_ID, $feedId), 'handle']);
                $this->fail('Expected missing CaseQty to fail authoring.');
            } catch (\Throwable) {
                // expected
            }

            $feed = L3LotFeed::query()->find($feedId);
            $this->assertSame('failed', $feed?->status);
            $this->assertStringContainsString('CaseQty', (string) $feed?->error_summary);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * C1 companion — without LotProcessedTime, authored XML must stay stable across
     * retries (no `now()` drift that changes SHA / breaks duplicate-hash recovery).
     */
    #[Test]
    public function authoring_without_lot_processed_time_is_stable_across_retries(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $xml = str_replace(
                '<LotProcessedTime>2026-08-27T13:43:50</LotProcessedTime>',
                '',
                $this->fixtureXml(),
            );

            $tmp = tempnam(sys_get_temp_dir(), 'guardian_author_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            try {
                $parsed = app(GuardianDataFeedParser::class)->parse($tmp);
            } finally {
                @unlink($tmp);
            }

            $this->assertNull($parsed['lot_processed_at'] ?? null);

            Carbon::setTestNow('2026-01-01 00:00:00');
            $first = app(AuthorGuardianLotEpcisDocument::class)->handle(
                $parsed,
                null,
                'guardian-lot:stable-base-time',
            );

            Carbon::setTestNow('2026-06-15 12:34:56');
            $second = app(AuthorGuardianLotEpcisDocument::class)->handle(
                $parsed,
                null,
                'guardian-lot:stable-base-time',
            );

            $this->assertSame($first['xml'], $second['xml']);
        } finally {
            Carbon::setTestNow();
            $this->cleanup();
        }
    }

    /**
     * Finding 8 — correlation headers must emit real org/site GLNs, never 0000000000000.
     */
    #[Test]
    public function authored_document_with_correlation_uses_real_sbdh_glns(): void
    {
        $tenant = $this->initializeDemo2Tenant();
        $this->configureTenant($tenant);

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'guardian_author_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $this->fixtureXml());

            try {
                $parsed = app(GuardianDataFeedParser::class)->parse($tmp);
            } finally {
                @unlink($tmp);
            }

            $site = Site::query()->where('gln', self::SITE_GLN)->firstOrFail();
            $result = app(AuthorGuardianLotEpcisDocument::class)->handle(
                $parsed,
                (int) $site->getKey(),
                'guardian-lot:sbdh-gln-test',
            );

            $this->assertStringContainsString('<sbdh:StandardBusinessDocumentHeader>', $result['xml']);
            $this->assertStringContainsString(
                '<sbdh:Identifier Authority="GLN">'.self::SITE_GLN.'</sbdh:Identifier>',
                $result['xml'],
            );
            $this->assertStringNotContainsString('0000000000000', $result['xml']);
        } finally {
            $this->cleanup();
        }
    }

    private function fixtureXml(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/guardian/lot_close_1p2c.xml'));
    }

    private function guardianPost(string $body, string $bearerToken): TestResponse
    {
        $absolute = 'http://'.self::DEMO2_DOMAIN.'/api/v1/l3/guardian/lot-close';

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            [],
            [
                'HTTP_HOST' => self::DEMO2_DOMAIN,
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/xml',
                'HTTP_AUTHORIZATION' => 'Bearer '.$bearerToken,
            ],
            $body,
        );
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Manufacturer',
                'profile' => TenantProfile::Manufacturer,
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

    private function configureTenant(
        Tenant $tenant,
        bool $guardianEnabled = true,
        TenantProfile $profile = TenantProfile::Manufacturer,
        ?string $provider = 'systech',
        bool $killInboundEpcis = false,
    ): void {
        $settings = TenantSettings::forTenant($tenant);

        if (! $this->captured) {
            $this->priorProfile = $tenant->profile instanceof TenantProfile
                ? $tenant->profile
                : TenantProfile::from((string) $tenant->profile);
            $this->priorL3Enabled = $settings->l3Enabled();
            $this->priorL3Provider = $settings->l3Provider();
            $this->priorGuardianEnabled = $settings->l3GuardianLotCloseEnabled();
            $this->priorGln = $settings->gln();
            $this->priorCompanyPrefix = $settings->companyPrefix();
            $this->priorInboundEpcisKilled = $settings->inboundEpcisKilled();
            $this->captured = true;
        }

        $tenant->setAttribute('profile', $profile);

        $settings->setL3Enabled(true);
        $settings->setL3Provider($provider);
        $settings->setL3ApiKey(self::API_KEY);
        $settings->setL3GuardianLotCloseEnabled($guardianEnabled);
        $settings->setGln('0301160000010');
        $settings->setCompanyPrefix(self::COMPANY_PREFIX);
        $settings->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, $killInboundEpcis);
        $tenant->save();

        $site = Site::query()->where('gln', self::SITE_GLN)->first();
        if ($site === null) {
            $site = Site::query()->create([
                'name' => 'Guardian Lot-Close Test Site',
                'gln' => self::SITE_GLN,
                'is_active' => true,
                'is_organization_facility' => true,
                'trading_partner_id' => null,
            ]);
        }
        $this->siteIds[] = (int) $site->getKey();
    }

    private function cleanupFixtureEpcs(): void
    {
        $uris = [
            self::BOTTLE_URI,
            'urn:epc:id:sgtin:030116.0200116.10000083545269',
            'urn:epc:id:sgtin:030116.0200116.10000083545272',
            'urn:epc:id:sgtin:030116.0200116.10000083546417',
            'urn:epc:id:sgtin:030116.0200116.10000083546418',
            'urn:epc:id:sgtin:030116.0200116.10000083546429',
            self::CASE_URI,
            'urn:epc:id:sgtin:030116.5200116.10000009679763',
            self::PALLET_URI,
        ];

        $ids = DB::table('epcs')->whereIn('epc_uri', $uris)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        $eventIds = DB::table('event_epcs')->whereIn('epc_id', $ids)->pluck('event_id');
        $documentIdsFromEvents = $eventIds->isEmpty()
            ? collect()
            : DB::table('epcis_events')->whereIn('id', $eventIds)->pluck('document_id');

        if (Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->whereIn('epc_id', $ids)->delete();
        }

        DB::table('aggregation_links')
            ->where(function ($query) use ($ids): void {
                $query->whereIn('parent_epc_id', $ids)->orWhereIn('child_epc_id', $ids);
            })
            ->delete();
        if (Schema::hasTable('aggregation_links_archive')) {
            DB::table('aggregation_links_archive')
                ->where(function ($query) use ($ids): void {
                    $query->whereIn('parent_epc_id', $ids)->orWhereIn('child_epc_id', $ids);
                })
                ->delete();
        }
        DB::table('event_epcs')->whereIn('epc_id', $ids)->delete();
        if (Schema::hasTable('epc_ilmd')) {
            DB::table('epc_ilmd')->whereIn('epc_id', $ids)->delete();
        }

        if ($eventIds->isNotEmpty()) {
            foreach ([
                'event_biz_transactions',
                'event_source_dest',
                'event_error_declarations',
                'event_sensors',
                'event_persistent_dispositions',
                'event_epc_ilmd',
            ] as $childTable) {
                if (Schema::hasTable($childTable)) {
                    DB::table($childTable)->whereIn('event_id', $eventIds)->delete();
                }
            }
            DB::table('epcis_events')->whereIn('id', $eventIds)->delete();
        }

        foreach ($documentIdsFromEvents->filter()->unique() as $documentId) {
            if (! in_array((int) $documentId, $this->documentIds, true)) {
                $this->documentIds[] = (int) $documentId;
            }
        }

        DB::table('epcs')->whereIn('id', $ids)->delete();
    }

    private function cleanupDocuments(array $documentIds): void
    {
        if ($documentIds === []) {
            return;
        }

        $eventIds = DB::table('epcis_events')->whereIn('document_id', $documentIds)->pluck('id');

        if ($eventIds->isNotEmpty()) {
            foreach ([
                'event_epcs',
                'event_biz_transactions',
                'event_source_dest',
                'event_error_declarations',
                'event_sensors',
                'event_persistent_dispositions',
                'event_epc_ilmd',
                'aggregation_links',
            ] as $childTable) {
                if (! Schema::hasTable($childTable)) {
                    continue;
                }

                if ($childTable === 'aggregation_links') {
                    DB::table($childTable)
                        ->where(function ($q) use ($eventIds): void {
                            $q->whereIn('established_by_event_id', $eventIds);
                            if (Schema::hasColumn('aggregation_links', 'closed_by_event_id')) {
                                $q->orWhereIn('closed_by_event_id', $eventIds);
                            }
                        })
                        ->delete();

                    continue;
                }

                DB::table($childTable)->whereIn('event_id', $eventIds)->delete();
            }

            DB::table('epcis_events')->whereIn('id', $eventIds)->delete();
        }

        if (Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->whereIn('document_id', $documentIds)->delete();
        }

        foreach ($documentIds as $documentId) {
            EpcisDocument::query()->whereKey($documentId)->delete();
        }
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
        }

        $this->cleanupFixtureEpcs();
        $this->cleanupDocuments($this->documentIds);

        foreach ($this->lotIds as $lotId) {
            SerializationLotContainerField::query()->where('lot_id', $lotId)->delete();
            SerializationLot::query()->whereKey($lotId)->delete();
        }

        foreach ($this->feedIds as $feedId) {
            L3LotFeed::query()->whereKey($feedId)->delete();
        }

        // Catch any lot/feed rows created by a run that failed before IDs were tracked.
        SerializationLot::query()
            ->whereIn('lot_number', ['608464T', '608464T-CORRECTED'])
            ->get()
            ->each(function (SerializationLot $lot): void {
                SerializationLotContainerField::query()->where('lot_id', $lot->getKey())->delete();
                $lot->delete();
            });
        L3LotFeed::query()->where('message_id', '8061EE51-05B4-4F9C-BDE4-82D34601D69E')->delete();
        L3LotFeed::query()->where('message_id', 'LEGACY-MESSAGE-ID-DIFFERENT-FROM-BODY')->delete();
        L3LotFeed::query()->whereIn('message_id', [
            'FEED-A-ACCEPTED-LOT',
            'FEED-B-BUNDLE-FAIL',
            'SHA-MATCH-OTHER-MESSAGE-ID',
            'CONTAINER-WIPE-FAIL-FEED',
        ])->delete();

        // Authored Guardian docs use a deterministic correlation-derived document_uuid;
        // purge stragglers so the next test does not hit the unique index.
        $strayDocumentIds = EpcisDocument::query()
            ->where('document_uuid', 'like', 'guardian-lot:%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $this->cleanupDocuments($strayDocumentIds);

        foreach ($this->siteIds as $siteId) {
            Site::query()->whereKey($siteId)->delete();
        }

        $this->documentIds = [];
        $this->lotIds = [];
        $this->feedIds = [];
        $this->siteIds = [];

        if ($this->captured) {
            $tenant = tenant();
            $settings = TenantSettings::forTenant($tenant);
            $tenant->setAttribute('profile', $this->priorProfile);
            $settings->setL3Enabled((bool) $this->priorL3Enabled);
            $settings->setL3Provider($this->priorL3Provider);
            $settings->setL3GuardianLotCloseEnabled((bool) $this->priorGuardianEnabled);
            $settings->setGln($this->priorGln);
            $settings->setCompanyPrefix($this->priorCompanyPrefix);
            $settings->setKillSwitch(TenantKillSwitches::INBOUND_EPCIS, (bool) $this->priorInboundEpcisKilled);
            $settings->setL3ApiKey(null);
            $tenant->save();
        }

        tenancy()->end();
    }
}

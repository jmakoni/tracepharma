<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AggregationLinkReprocessRetirementTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function failed_first_ingest_closes_this_attempt_aggregation_links(): void
    {
        $this->initializeDemo2Tenant();

        $this->app->bind(ValidateEpcis12Document::class, fn () => new class
        {
            public function handle(EpcisDocument $document, ?string $absolutePath = null): void
            {
                throw new \RuntimeException('Forced first-ingest validation failure for aggregation close test.');
            }
        });

        try {
            $this->assertTrue(Schema::hasTable('aggregation_links'));
            $this->assertTrue(Schema::hasColumn('aggregation_links', 'valid_to'));

            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            try {
                app(EpcisIngestionService::class)->process($document);
            } catch (\RuntimeException) {
                // Expected: validator throws after events and aggregation links persist.
            }

            $document->refresh();
            $this->assertSame('error', $document->status);

            $attemptEventIds = EpcisEvent::query()
                ->where('document_id', $document->id)
                ->pluck('id');
            $this->assertNotEmpty($attemptEventIds, 'Failed first ingest must keep generation-1 events for the document view.');

            $openThisAttempt = AggregationLink::query()
                ->whereIn('established_by_event_id', $attemptEventIds)
                ->whereNull('valid_to')
                ->count();
            $this->assertSame(
                0,
                $openThisAttempt,
                'Open aggregation_links established by a failed first ingest must be closed',
            );

            $this->assertSame(
                1,
                AggregationLink::query()
                    ->whereIn('established_by_event_id', $attemptEventIds)
                    ->whereNotNull('valid_to')
                    ->count(),
                'The Aggregation ADD from this attempt should remain as a closed link',
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_first_ingest_restores_foreign_packing_links_closed_by_add(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertFailedIngestRestoresForeignPackingLink(function (): void {
                $this->app->bind(ValidateEpcis12Document::class, fn () => new class
                {
                    public function handle(EpcisDocument $document, ?string $absolutePath = null): void
                    {
                        throw new \RuntimeException('Forced first-ingest failure after closing a foreign packing link.');
                    }
                });
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function failed_first_ingest_status_error_restores_foreign_packing_links_closed_by_add(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertFailedIngestRestoresForeignPackingLink(function (): void {
                $this->app->bind(ValidateEpcis12Document::class, fn () => new class
                {
                    public function handle(EpcisDocument $document, ?string $absolutePath = null): array
                    {
                        $document->forceFill([
                            'status' => 'error',
                            'error_message' => 'Forced validation failure after closing a foreign packing link.',
                        ])->save();

                        return [];
                    }
                });
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reprocess_validation_failure_keeps_prior_generation_aggregation_links_open(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('aggregation_links'));
            $this->assertTrue(Schema::hasColumn('aggregation_links', 'valid_to'));

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
            $this->assertSame(1, (int) $document->ingest_generation);

            $gen1EventIds = EpcisEvent::query()
                ->where('document_id', $document->id)
                ->where('ingest_generation', 1)
                ->pluck('id');
            $this->assertNotEmpty($gen1EventIds);

            $openGen1Before = AggregationLink::query()
                ->whereIn('established_by_event_id', $gen1EventIds)
                ->whereNull('valid_to')
                ->count();
            $this->assertSame(1, $openGen1Before);

            $priorGeneration = (int) $document->ingest_generation;

            $this->app->bind(ValidateEpcis12Document::class, fn () => new class
            {
                public function handle(EpcisDocument $document, ?string $absolutePath = null): array
                {
                    $document->forceFill([
                        'status' => 'error',
                        'error_message' => 'Forced validation failure for aggregation rollback test.',
                    ])->save();

                    return [];
                }
            });

            app(ReprocessEpcisDocument::class)->handle($document, sync: true);
            $document->refresh();

            $this->assertSame('error', $document->status);
            $this->assertSame($priorGeneration, (int) $document->ingest_generation);

            $openGen1After = AggregationLink::query()
                ->whereIn('established_by_event_id', $gen1EventIds)
                ->whereNull('valid_to')
                ->count();
            $this->assertSame(
                $openGen1Before,
                $openGen1After,
                'Prior-generation aggregation links must stay open when reprocess validation fails',
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reprocess_retires_prior_generation_aggregation_links(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('aggregation_links'));
            $this->assertTrue(Schema::hasColumn('aggregation_links', 'valid_to'));

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
            $this->assertSame(1, (int) $document->ingest_generation);

            $gen1EventIds = EpcisEvent::query()
                ->where('document_id', $document->id)
                ->where('ingest_generation', 1)
                ->pluck('id');
            $this->assertNotEmpty($gen1EventIds);

            $openGen1Before = AggregationLink::query()
                ->whereIn('established_by_event_id', $gen1EventIds)
                ->whereNull('valid_to')
                ->count();
            $this->assertSame(1, $openGen1Before);

            $reprocessed = app(ReprocessEpcisDocument::class)->handle($document, sync: true);

            $this->assertGreaterThanOrEqual(2, (int) $reprocessed->ingest_generation);

            $openGen1After = AggregationLink::query()
                ->whereIn('established_by_event_id', $gen1EventIds)
                ->whereNull('valid_to')
                ->count();
            $this->assertSame(0, $openGen1After, 'Prior-generation aggregation links must be closed on reprocess');

            $closedGen1 = AggregationLink::query()
                ->whereIn('established_by_event_id', $gen1EventIds)
                ->whereNotNull('valid_to')
                ->count();
            $this->assertSame(1, $closedGen1);

            $currentEventIds = EpcisEvent::query()
                ->where('document_id', $reprocessed->id)
                ->where('ingest_generation', $reprocessed->ingest_generation)
                ->pluck('id');

            $openCurrent = AggregationLink::query()
                ->whereIn('established_by_event_id', $currentEventIds)
                ->whereNull('valid_to')
                ->count();
            $this->assertSame(1, $openCurrent);

            $this->assertSame(
                1,
                AggregationLink::query()
                    ->whereNull('valid_to')
                    ->whereIn('established_by_event_id', function ($q) use ($reprocessed): void {
                        $q->select('id')
                            ->from('epcis_events')
                            ->where('document_id', $reprocessed->id);
                    })
                    ->count(),
                'Exactly one open aggregation link should remain for the document',
            );

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @param  callable(): void  $bindFailingValidator
     */
    private function assertFailedIngestRestoresForeignPackingLink(callable $bindFailingValidator): void
    {
        $this->assertTrue(Schema::hasTable('aggregation_links'));
        $this->assertTrue(Schema::hasColumn('aggregation_links', 'valid_to'));

        $childSgtin = 'urn:epc:id:sgtin:030116.0200116.10000082001560';
        $packingParentUrn = 'urn:epc:id:sscc:030116.01001888001';
        $otherParentUrn = 'urn:epc:id:sscc:030116.01001227052';

        $packing = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'seeded-packing-link.xml',
            'payload_disk' => 'local',
            'payload_path' => 'epcis/outbound/seeded-packing-'.Str::uuid().'.xml',
            'file_sha256' => hash('sha256', (string) Str::uuid()),
            'status' => 'validated',
            'ingest_generation' => 1,
            'processed_at' => now(),
            'event_count' => 1,
            'epc_count' => 2,
            'received_at' => now(),
        ]);
        $this->documentIds[] = (int) $packing->getKey();

        $packingEvent = EpcisEvent::query()->create([
            'document_id' => $packing->getKey(),
            'ingest_generation' => 1,
            'event_type' => 'AggregationEvent',
            'event_time' => now()->subHour(),
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
        ]);

        $child = Epc::query()->firstOrCreate(
            ['epc_uri' => $childSgtin],
            Epc::materializeAttributesFromUri($childSgtin),
        );
        $packingParent = Epc::query()->firstOrCreate(
            ['epc_uri' => $packingParentUrn],
            Epc::materializeAttributesFromUri($packingParentUrn),
        );

        $packingLinkId = DB::table('aggregation_links')->insertGetId([
            'parent_epc_id' => $packingParent->getKey(),
            'child_epc_id' => $child->getKey(),
            'established_by_event_id' => $packingEvent->getKey(),
            'link_type' => 'aggregation',
            'valid_from' => '2026-06-01 00:00:00',
            'valid_to' => null,
            'created_at' => now(),
        ]);

        $bindFailingValidator();

        [$failXml] = $this->uniqueAggregationFixture($otherParentUrn, $childSgtin);
        Storage::fake('local');
        $path = 'epcis/inbound/failed-add-foreign-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, (string) file_get_contents($failXml));

        $failed = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'failed-add-foreign-child.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', (string) file_get_contents($failXml)),
            'status' => 'received',
            'received_at' => now(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
        ]);
        $this->documentIds[] = (int) $failed->getKey();

        try {
            app(EpcisIngestionService::class)->process($failed);
        } catch (\Throwable) {
            // Expected when the validator throws after persist.
        }

        $failed->refresh();
        $this->assertSame('error', $failed->status, (string) $failed->error_message);
        $this->assertNotEmpty(
            EpcisEvent::query()->where('document_id', $failed->id)->pluck('id'),
            'Failed first ingest must keep generation-1 events.',
        );

        $this->assertNull(
            DB::table('aggregation_links')->where('id', $packingLinkId)->value('valid_to'),
            'Foreign packing link closed by a failed ingest ADD must be restored',
        );

        $failedEventIds = EpcisEvent::query()->where('document_id', $failed->id)->pluck('id');
        $this->assertSame(
            0,
            AggregationLink::query()
                ->whereIn('established_by_event_id', $failedEventIds)
                ->whereNull('valid_to')
                ->count(),
            'This-attempt ADD links from the failed ingest must be closed',
        );

        @unlink($failXml);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uniqueAggregationFixture(string $parentSscc, string $childSgtin): array
    {
        $fixture = base_path('tests/Fixtures/epcis/minimal_object_shipping.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_agg_foreign_');
        $this->assertNotFalse($tmp);
        $xmlPath = $tmp.'.xml';
        rename($tmp, $xmlPath);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        $xml = str_replace('urn:epc:id:sscc:030116.01001227052', $parentSscc, $xml);
        $xml = str_replace('urn:epc:id:sgtin:030116.0200116.10000082001560', $childSgtin, $xml);
        file_put_contents($xmlPath, $xml);

        return [$xmlPath, $uuid];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uniqueFixture(string $relativePath): array
    {
        $fixture = base_path($relativePath);
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_agg_');
        $this->assertNotFalse($tmp);
        $xmlPath = $tmp.'.xml';
        rename($tmp, $xmlPath);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('11111111-2222-3333-4444-555555555555', $uuid, $xml);
        file_put_contents($xmlPath, $xml);

        return [$xmlPath, $uuid];
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
            ]);
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()->whereIn('document_id', $this->documentIds)->pluck('id');
            if ($eventIds->isNotEmpty()) {
                DB::table('aggregation_links')->whereIn('established_by_event_id', $eventIds)->delete();
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
            }
            EpcisEvent::query()->whereIn('document_id', $this->documentIds)->delete();
            DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}

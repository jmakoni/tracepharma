<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\TenantProfile;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Labeling;

use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Enums\EpcisAuthoredKind;
use App\Enums\TenantProfile;
use App\Jobs\Labeling\ForwardCommissioningToL3;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Tenant;
use App\Support\TenantSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ForwardCommissioningToL3Test extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const L3_ENDPOINT = 'https://8.8.8.8/commission';

    private const L3_ENDPOINT_WITH_SECRETS = 'https://8.8.8.8/commission/submit?token=abc123&tenant=xyz';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?bool $priorL3Enabled = null;

    private ?string $priorL3Endpoint = null;

    #[Test]
    public function persist_dispatches_l3_forward_job_when_l3_enabled(): void
    {
        Queue::fake();

        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            $this->enableL3();

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument test="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-dispatch-'.Str::uuid().'.xml';

            $document = app(PersistAuthoredSsccEpcis::class)->handle($xml, $path, [
                'dispatch' => false,
                'original_filename' => 'l3-dispatch.xml',
                'authored_kind' => EpcisAuthoredKind::SsccCommissioning,
            ]);

            $this->documentId = (int) $document->getKey();

            Queue::assertPushed(
                ForwardCommissioningToL3::class,
                fn (ForwardCommissioningToL3 $job): bool => $job->tenantId === self::DEMO2_TENANT_ID
                    && $job->documentId === $this->documentId,
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function persist_skips_l3_forward_for_non_commissioning_kind(): void
    {
        Queue::fake();

        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            $this->enableL3();

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument test="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-skip-'.Str::uuid().'.xml';

            $document = app(PersistAuthoredSsccEpcis::class)->handle($xml, $path, [
                'dispatch' => false,
                'original_filename' => 'l3-skip.xml',
                'authored_kind' => EpcisAuthoredKind::SsccAggregation,
            ]);

            $this->documentId = (int) $document->getKey();

            Queue::assertNotPushed(ForwardCommissioningToL3::class);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_posts_authored_xml_and_records_failure_on_http_error(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            $this->enableL3();

            Http::fake([
                self::L3_ENDPOINT => Http::response('rejected', 502),
            ]);

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument l3="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-forward-'.Str::uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::SsccCommissioning,
                'format' => 'xml',
                'original_filename' => 'l3-forward.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            try {
                (new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, $this->documentId))
                    ->handle(app(RecordOperationalEpcisCatalogSignal::class));
                $this->fail('Expected L3 forward to throw on HTTP 502.');
            } catch (\RuntimeException) {
                // expected
            }

            Http::assertSent(fn ($request): bool => $request->url() === self::L3_ENDPOINT
                && $request->body() === $xml);

            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $this->documentId)
                    ->where('exception_type', 'L3_TRANSMISSION_FAILURE')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_redacts_endpoint_secrets_in_failure_logs(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            TenantSettings::forTenant(tenant())
                ->setL3Enabled(true)
                ->setL3EndpointUrl(self::L3_ENDPOINT_WITH_SECRETS);
            tenant()->save();

            Log::shouldReceive('warning')
                ->once()
                ->withArgs(function (string $message, array $context): bool {
                    return $message === 'External L3 commissioning forward failed.'
                        && ($context['endpoint'] ?? null) === 'https://8.8.8.8/commission/submit'
                        && ! str_contains((string) ($context['endpoint'] ?? ''), 'supersecret')
                        && ! str_contains((string) ($context['endpoint'] ?? ''), 'token=');
                });

            Http::fake([
                '8.8.8.8/*' => Http::response('rejected', 502),
            ]);

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument l3="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-redact-'.Str::uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::SsccCommissioning,
                'format' => 'xml',
                'original_filename' => 'l3-redact.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            try {
                (new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, $this->documentId))
                    ->handle(app(RecordOperationalEpcisCatalogSignal::class));
            } catch (\RuntimeException) {
                // expected
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_sends_l3_api_key_headers_when_configured(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            $settings = TenantSettings::forTenant(tenant());
            $settings
                ->setL3Enabled(true)
                ->setL3EndpointUrl(self::L3_ENDPOINT)
                ->setL3ApiKey('tenant-l3-secret-key');
            tenant()->save();

            Http::fake([
                self::L3_ENDPOINT => Http::response('<mdn/>', 200),
            ]);

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument key="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-key-'.Str::uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::Commissioning,
                'format' => 'xml',
                'original_filename' => 'l3-key.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            (new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, $this->documentId))
                ->handle(app(RecordOperationalEpcisCatalogSignal::class));

            Http::assertSent(function ($request): bool {
                return $request->url() === self::L3_ENDPOINT
                    && $request->hasHeader('Authorization', 'Bearer tenant-l3-secret-key')
                    && $request->hasHeader('X-L3-Api-Key', 'tenant-l3-secret-key');
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_succeeds_on_http_200(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            $this->enableL3();

            Http::fake([
                self::L3_ENDPOINT => Http::response('<mdn/>', 200),
            ]);

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument ok="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-ok-'.Str::uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::Commissioning,
                'format' => 'xml',
                'original_filename' => 'l3-ok.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            (new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, $this->documentId))
                ->handle(app(RecordOperationalEpcisCatalogSignal::class));

            Http::assertSentCount(1);

            $this->assertFalse(
                EpcisException::query()
                    ->where('document_id', $this->documentId)
                    ->where('exception_type', 'L3_TRANSMISSION_FAILURE')
                    ->exists(),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function set_l3_endpoint_rejects_private_url_like_runtime_guard(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('private or metadata host');

            TenantSettings::forTenant(tenant())
                ->setL3Enabled(true)
                ->setL3EndpointUrl('https://10.0.0.1/commission');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_rejects_private_l3_endpoint_before_post(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            TenantSettings::forTenant(tenant())->setL3Enabled(true);
            // Bypass setter to simulate a legacy stored private URL still present in settings.
            $settings = tenant()->settings ?? [];
            if (! is_array($settings)) {
                $settings = [];
            }
            data_set($settings, 'l3.endpoint_url', 'https://10.0.0.1/commission');
            tenant()->setAttribute('settings', $settings);
            tenant()->save();

            Http::fake();

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument ssrf="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-ssrf-'.Str::uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::SsccCommissioning,
                'format' => 'xml',
                'original_filename' => 'l3-ssrf.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            try {
                (new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, $this->documentId))
                    ->handle(app(RecordOperationalEpcisCatalogSignal::class));
                $this->fail('Expected private L3 endpoint to be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('private or metadata host', $e->getMessage());
            }

            Http::assertNothingSent();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_is_unique_per_tenant_and_document(): void
    {
        $job = new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, 42);
        $other = new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, 43);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(self::DEMO2_TENANT_ID.':42', $job->uniqueId());
        $this->assertNotSame($job->uniqueId(), $other->uniqueId());

        $middleware = $job->middleware();
        $this->assertNotEmpty($middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    #[Test]
    public function job_second_handle_after_success_does_not_post_again(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            $this->enableL3();

            Http::fake([
                self::L3_ENDPOINT => Http::response('<mdn/>', 200),
            ]);

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument replay="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-replay-'.Str::uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::Commissioning,
                'format' => 'xml',
                'original_filename' => 'l3-replay.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $job = new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, $this->documentId);
            $recordSignal = app(RecordOperationalEpcisCatalogSignal::class);

            $job->handle($recordSignal);
            $job->handle($recordSignal);

            Http::assertSentCount(1);
            Http::assertSent(function ($request): bool {
                return $request->url() === self::L3_ENDPOINT
                    && $request->hasHeader(
                        'Idempotency-Key',
                        'l3-commission:'.self::DEMO2_TENANT_ID.':'.$this->documentId,
                    );
            });

            $this->assertNotNull($document->fresh()?->l3_forwarded_at);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_releases_l3_forwarded_claim_on_http_failure_so_retry_can_repost(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Storage::fake('local');
            $this->enableL3();

            Http::fake([
                self::L3_ENDPOINT => Http::sequence()
                    ->push('rejected', 502)
                    ->push('<mdn/>', 200),
            ]);

            $xml = '<?xml version="1.0" encoding="UTF-8"?><EPCISDocument claim="'.Str::uuid().'"/>';
            $path = 'epcis/outbound/l3-claim-'.Str::uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'authored_kind' => EpcisAuthoredKind::SsccCommissioning,
                'format' => 'xml',
                'original_filename' => 'l3-claim.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'received',
                'reprocess_count' => 0,
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $job = new ForwardCommissioningToL3(self::DEMO2_TENANT_ID, $this->documentId);
            $recordSignal = app(RecordOperationalEpcisCatalogSignal::class);

            try {
                $job->handle($recordSignal);
                $this->fail('Expected first attempt to throw.');
            } catch (\RuntimeException) {
                // expected
            }

            $this->assertNull($document->fresh()?->l3_forwarded_at);

            $job->handle($recordSignal);

            Http::assertSentCount(2);
            $this->assertNotNull($document->fresh()?->l3_forwarded_at);
        } finally {
            $this->cleanup();
        }
    }

    private function enableL3(): void
    {
        $settings = TenantSettings::forTenant(tenant());
        $this->priorL3Enabled = $settings->l3Enabled();
        $this->priorL3Endpoint = $settings->l3EndpointUrl();

        // Setters + save avoid saveOrganization's GLN/prefix assert (demo2 test DB can drift).
        $settings
            ->setL3Enabled(true)
            ->setL3EndpointUrl(self::L3_ENDPOINT);
        tenant()->save();
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

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                EpcisException::query()->where('document_id', $this->documentId)->delete();
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            TenantSettings::forTenant(tenant())
                ->setL3Enabled($this->priorL3Enabled ?? false)
                ->setL3EndpointUrl($this->priorL3Endpoint);
            tenant()->save();
            $this->priorL3Enabled = null;
            $this->priorL3Endpoint = null;

            tenancy()->end();
        }
    }
}

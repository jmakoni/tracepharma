<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\DispatchEpcisSubscriptions;
use App\Enums\TenantProfile;
use App\Jobs\DeliverEpcisSubscriptionJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisSubscription;
use App\Models\Epcis\EpcisSubscriptionDelivery;
use App\Models\Tenant;
use App\Services\Epcis\Outbound\CanonicalEventsToJsonLd20;
use App\Support\Epcis\EpcisSubscriptionUrl;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EpcisSubscriptionDeliveryTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $subscriptionIds = [];

    #[Test]
    public function validated_outbound_does_not_dispatch_outbound_subscription(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Outbound sent hook',
                'target_url' => 'https://hooks.example.com/epcis-out',
                'secret' => 'test-secret-value-32chars-minimum!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_OUTBOUND,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'outbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            app(DispatchEpcisSubscriptions::class)->handle($document, 'validated');

            Queue::assertNothingPushed();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function sent_outbound_dispatches_outbound_subscription(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Outbound sent hook',
                'target_url' => 'https://hooks.example.com/epcis-out',
                'secret' => 'test-secret-value-32chars-minimum!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_OUTBOUND,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'outbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            app(DispatchEpcisSubscriptions::class)->handle($document, 'sent');

            Queue::assertPushed(DeliverEpcisSubscriptionJob::class, function (DeliverEpcisSubscriptionJob $job) use ($subscription, $document): bool {
                return $job->subscriptionId === (int) $subscription->getKey()
                    && $job->documentId === (int) $document->getKey()
                    && $job->trigger === 'sent';
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function validated_inbound_dispatches_active_subscription_job(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Inbound validated hook',
                'target_url' => 'https://hooks.example.com/epcis',
                'secret' => 'test-secret-value-32chars-minimum!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_INBOUND,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            app(DispatchEpcisSubscriptions::class)->handle($document, 'validated');

            Queue::assertPushed(DeliverEpcisSubscriptionJob::class, function (DeliverEpcisSubscriptionJob $job) use ($subscription, $document): bool {
                return $job->subscriptionId === (int) $subscription->getKey()
                    && $job->documentId === (int) $document->getKey()
                    && $job->trigger === 'validated';
            });
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function subscription_dispatch_waits_for_transaction_commit(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Inbound validated hook',
                'target_url' => 'https://hooks.example.com/epcis',
                'secret' => 'test-secret-value-32chars-minimum!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_INBOUND,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'parsed',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            DB::transaction(function () use ($document): void {
                $document->forceFill(['status' => 'validated', 'error_message' => null])->save();
                app(DispatchEpcisSubscriptions::class)->handle($document->fresh(), 'validated');
                Queue::assertNothingPushed();
            });

            Queue::assertPushed(DeliverEpcisSubscriptionJob::class, function (DeliverEpcisSubscriptionJob $job) use ($subscription, $document): bool {
                return $job->subscriptionId === (int) $subscription->getKey()
                    && $job->documentId === (int) $document->getKey()
                    && $job->trigger === 'validated';
            });
            $this->assertSame('validated', (string) $document->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function subscription_dispatch_is_dropped_when_transaction_rolls_back(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Inbound validated hook',
                'target_url' => 'https://hooks.example.com/epcis',
                'secret' => 'test-secret-value-32chars-minimum!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_INBOUND,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'parsed',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'epc_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            try {
                DB::transaction(function () use ($document): void {
                    $document->forceFill(['status' => 'validated', 'error_message' => null])->save();
                    app(DispatchEpcisSubscriptions::class)->handle($document->fresh(), 'validated');
                    throw new RuntimeException('force rollback');
                });
            } catch (RuntimeException $e) {
                $this->assertSame('force rollback', $e->getMessage());
            }

            Queue::assertNothingPushed();
            $this->assertSame('parsed', (string) $document->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function inactive_subscription_is_skipped(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Inactive',
                'target_url' => 'https://hooks.example.com/epcis',
                'secret' => 'test-secret-value-32chars-minimum!!',
                'is_active' => false,
                'directions' => EpcisSubscription::DIRECTION_BOTH,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            app(DispatchEpcisSubscriptions::class)->handle($document, 'validated');

            Queue::assertNothingPushed();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function biz_step_filter_skips_non_matching_documents(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Queue::fake();

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Shipping only',
                'target_url' => 'https://hooks.example.com/epcis',
                'secret' => 'test-secret-value-32chars-minimum!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_BOTH,
                'biz_step_filter' => ['urn:epcglobal:cbv:bizstep:shipping'],
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            // No events → empty bizSteps → filter fails closed (skip)
            app(DispatchEpcisSubscriptions::class)->handle($document, 'validated');

            Queue::assertNothingPushed();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delivery_job_posts_hmac_signed_payload(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://8.8.8.8/*' => Http::response(['ok' => true], 200),
            ]);

            $subscription = EpcisSubscription::query()->create([
                'name' => 'HMAC hook',
                'target_url' => 'https://8.8.8.8/epcis',
                'secret' => 'super-secret-hmac-key-for-tests!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_BOTH,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 100,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            tenancy()->end();

            (new DeliverEpcisSubscriptionJob(
                (string) $tenant->getKey(),
                (int) $subscription->getKey(),
                (int) $document->getKey(),
                'validated',
            ))->handle(app(CanonicalEventsToJsonLd20::class));

            Http::assertSent(function (Request $request) use ($subscription, $document): bool {
                if ($request->url() !== 'https://8.8.8.8/epcis') {
                    return false;
                }

                $signature = $request->header('X-TracePharma-Signature')[0] ?? '';
                if (! preg_match('/^t=(\d+),v1=([a-f0-9]+)$/', $signature, $matches)) {
                    return false;
                }

                $expected = hash_hmac('sha256', $matches[1].'.'.$request->body(), 'super-secret-hmac-key-for-tests!!');
                $data = json_decode($request->body(), true);

                return hash_equals($expected, $matches[2])
                    && ($data['document_uuid'] ?? null) === $document->document_uuid
                    && ($data['subscription_id'] ?? null) === $subscription->getKey()
                    && isset($data['download_url'])
                    && ! isset($data['epcis_document']); // event_count 100 > threshold
            });

            tenancy()->initialize($tenant);
            $this->assertNotNull($subscription->fresh()?->last_delivered_at);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function delivery_job_refuses_http_redirects(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://8.8.8.8/*' => Http::response('', 302, [
                    'Location' => 'http://169.254.169.254/latest/meta-data/',
                ]),
            ]);

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Redirect SSRF hook',
                'target_url' => 'https://8.8.8.8/epcis',
                'secret' => 'super-secret-hmac-key-for-tests!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_BOTH,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            tenancy()->end();

            try {
                (new DeliverEpcisSubscriptionJob(
                    (string) $tenant->getKey(),
                    (int) $subscription->getKey(),
                    (int) $document->getKey(),
                    'validated',
                ))->handle(app(CanonicalEventsToJsonLd20::class));
                $this->fail('Expected redirect delivery to throw.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('redirect', strtolower($exception->getMessage()));
            }

            tenancy()->initialize($tenant);
            $fresh = $subscription->fresh();
            $this->assertNull($fresh?->last_delivered_at);
            $this->assertNotNull($fresh?->last_error_at);
            $this->assertStringContainsString('redirect', strtolower((string) $fresh?->last_error));

            Http::assertSentCount(1);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function delivery_job_is_idempotent_for_subscription_document_pair(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://8.8.8.8/*' => Http::response(['ok' => true], 200),
            ]);

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Idempotent hook',
                'target_url' => 'https://8.8.8.8/epcis',
                'secret' => 'super-secret-hmac-key-for-tests!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_BOTH,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            tenancy()->end();

            $job = new DeliverEpcisSubscriptionJob(
                (string) $tenant->getKey(),
                (int) $subscription->getKey(),
                (int) $document->getKey(),
                'validated',
            );

            $this->assertInstanceOf(ShouldBeUnique::class, $job);
            $this->assertSame(
                (string) $tenant->getKey().':epcis-sub:'.$subscription->getKey().':doc:'.$document->getKey(),
                $job->uniqueId(),
            );

            $projector = app(CanonicalEventsToJsonLd20::class);
            $job->handle($projector);
            $job->handle($projector);

            Http::assertSentCount(1);

            tenancy()->initialize($tenant);
            $this->assertNotNull($subscription->fresh()?->last_delivered_at);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function delivery_releases_ledger_claim_on_http_failure(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://8.8.8.8/*' => Http::sequence()
                    ->push('nope', 500)
                    ->push(['ok' => true], 200),
            ]);

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Claim release hook',
                'target_url' => 'https://8.8.8.8/epcis',
                'secret' => 'super-secret-hmac-key-for-tests!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_BOTH,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            tenancy()->end();

            $job = new DeliverEpcisSubscriptionJob(
                (string) $tenant->getKey(),
                (int) $subscription->getKey(),
                (int) $document->getKey(),
                'validated',
            );
            $projector = app(CanonicalEventsToJsonLd20::class);

            try {
                $job->handle($projector);
                $this->fail('Expected HTTP 500 to throw.');
            } catch (RuntimeException) {
                // expected
            }

            tenancy()->initialize($tenant);
            $this->assertFalse(
                EpcisSubscriptionDelivery::query()
                    ->where('subscription_id', $subscription->getKey())
                    ->where('document_id', $document->getKey())
                    ->exists(),
            );

            tenancy()->end();
            $job->handle($projector);

            tenancy()->initialize($tenant);
            $this->assertTrue(
                EpcisSubscriptionDelivery::query()
                    ->where('subscription_id', $subscription->getKey())
                    ->where('document_id', $document->getKey())
                    ->exists(),
            );
            Http::assertSentCount(2);
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function delivery_skips_post_when_ledger_claim_already_exists(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://8.8.8.8/*' => Http::response(['ok' => true], 200),
            ]);

            $subscription = EpcisSubscription::query()->create([
                'name' => 'Preclaimed hook',
                'target_url' => 'https://8.8.8.8/epcis',
                'secret' => 'super-secret-hmac-key-for-tests!!',
                'is_active' => true,
                'directions' => EpcisSubscription::DIRECTION_BOTH,
                'format' => EpcisSubscription::FORMAT_JSONLD_20,
            ]);
            $this->subscriptionIds[] = (int) $subscription->getKey();

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'direction' => 'inbound',
                'status' => 'validated',
                'schema_version' => '1.2',
                'format' => 'xml',
                'creation_date' => now(),
                'event_count' => 1,
                'received_at' => now(),
            ]);
            $this->documentIds[] = (int) $document->getKey();

            EpcisSubscriptionDelivery::query()->create([
                'subscription_id' => $subscription->getKey(),
                'document_id' => $document->getKey(),
                'trigger' => 'validated',
                'delivered_at' => now(),
            ]);

            tenancy()->end();

            (new DeliverEpcisSubscriptionJob(
                (string) $tenant->getKey(),
                (int) $subscription->getKey(),
                (int) $document->getKey(),
                'validated',
            ))->handle(app(CanonicalEventsToJsonLd20::class));

            Http::assertNothingSent();
        } finally {
            if (! tenancy()->initialized) {
                tenancy()->initialize($tenant);
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function subscription_url_rejects_loopback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS');

        EpcisSubscriptionUrl::assertSafeTargetUrl('http://127.0.0.1/hook');
    }

    #[Test]
    public function subscription_url_rejects_private_https_ip(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private');

        EpcisSubscriptionUrl::assertSafeTargetUrl('https://127.0.0.1/hook');
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
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        $this->assertTrue(Schema::hasTable('epcis_subscriptions'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->subscriptionIds as $id) {
            EpcisSubscriptionDelivery::query()->where('subscription_id', $id)->delete();
            EpcisSubscription::query()->whereKey($id)->delete();
        }
        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->subscriptionIds = [];
        $this->documentIds = [];
        tenancy()->end();
    }
}

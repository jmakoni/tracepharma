<?php

namespace Tests\Feature\Vrs;

use App\Actions\Vrs\RunProductVerification;
use App\Enums\TenantProfile;
use App\Exceptions\VrsConfigurationException;
use App\Jobs\Vrs\RunProductVerificationJob;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Services\Vrs\Contracts\VrsClient;
use App\Support\SanctumAbilities;
use App\Support\Vrs\VrsLogCorrelation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VrsFailClosedHardeningTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    #[Test]
    public function null_driver_dispense_check_fails_closed_without_verified_row(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'null']);

            $before = Verification::query()->count();

            $user = User::factory()->create();
            $token = $user->createToken('dispense-test', [SanctumAbilities::VRS_DISPENSE_CHECK])->plainTextToken;

            tenancy()->end();

            $response = $this->tenantApiPost('/api/v1/dispense-check', $token, [
                'gtin14' => '30301164005162',
                'serial' => 'NULL-DRIVER-1',
            ]);

            $response->assertStatus(503)
                ->assertJson([
                    'allowed' => false,
                    'status' => 'unavailable',
                ]);
            $this->assertStringContainsString('VRS_DRIVER', (string) $response->json('message'));

            tenancy()->initialize(Tenant::query()->find(self::DEMO2_TENANT_ID));
            $this->assertSame($before, Verification::query()->count());
            $this->assertSame(0, Verification::query()->where('status', 'verified')->where('serial', 'NULL-DRIVER-1')->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function null_driver_run_product_verification_throws_before_persist(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'null']);
            $this->app->forgetInstance(VrsClient::class);

            $before = Verification::query()->count();

            try {
                app(RunProductVerification::class)->handle('(01)30301164005162(21)NULL-RUN-1');
                $this->fail('Expected VrsConfigurationException');
            } catch (VrsConfigurationException $e) {
                $this->assertStringContainsString('VRS_DRIVER', $e->getMessage());
            }

            $this->assertSame($before, Verification::query()->count());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function http_success_stores_verification_with_l4_snapshot_and_http_evidence(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'vrs.driver' => 'http',
                'vrs.http.base_url' => 'https://vrs.test',
                'vrs.http.verify_path' => '/verify',
                'vrs.http.api_key' => null,
            ]);
            $this->app->forgetInstance(VrsClient::class);

            Http::fake([
                'https://vrs.test/verify' => Http::response([
                    'verified' => true,
                    'message' => 'Product verified.',
                ], 200),
            ]);

            $result = app(RunProductVerification::class)->handle('(01)30301164005162(21)HTTP-OK-1');
            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();

            $this->assertSame('verified', $verification->status);
            $this->assertNotNull($verification->verified_at);

            $request = $verification->request_payload ?? [];
            $this->assertArrayHasKey('l4', $request);
            $this->assertArrayHasKey('terminal', $request['l4']);
            $this->assertFalse((bool) $request['l4']['terminal']);

            $response = $verification->response_payload ?? [];
            $this->assertSame('verified', $response['status'] ?? null);
            $this->assertSame(200, $response['http_status'] ?? null);
            $this->assertNotEmpty($response['http_body'] ?? null);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function http_timeout_persists_unavailable_not_verified(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'vrs.driver' => 'http',
                'vrs.http.base_url' => 'https://vrs.test',
                'vrs.http.verify_path' => '/verify',
            ]);
            $this->app->forgetInstance(VrsClient::class);

            Http::fake(function (): never {
                throw new ConnectionException(
                    'cURL error 28: Operation timed out after 30001 milliseconds',
                );
            });

            $result = app(RunProductVerification::class)->handle('(01)30301164005162(21)HTTP-TO-1');
            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();

            $this->assertSame('unavailable', $verification->status);
            $this->assertNull($verification->verified_at);
            $this->assertNotSame('verified', $verification->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function job_invalid_scan_log_contains_hash_not_raw_serial(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            Log::spy();

            $scan = '(01)30301164005162(21)RAW-SERIAL-LEAK';
            (new RunProductVerificationJob(self::DEMO2_TENANT_ID, 'not-a-valid-scan-payload', null))->handle();

            Log::shouldHaveReceived('info')
                ->withArgs(function (string $message, array $context): bool {
                    if ($message !== 'VRS verify skipped for invalid scan') {
                        return false;
                    }

                    $encoded = json_encode($context);

                    return isset($context['scan_hash'])
                        && ! array_key_exists('scan', $context)
                        && ! str_contains((string) $encoded, 'RAW-SERIAL-LEAK')
                        && $context['scan_hash'] === VrsLogCorrelation::scanHash('not-a-valid-scan-payload');
                })
                ->atLeast()
                ->once();

            unset($scan);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_verify_never_answers_decommissioned_epc_as_active(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config(['vrs.driver' => 'fake']);
            $this->app->forgetInstance(VrsClient::class);

            $serial = 'DC'.random_int(100000, 999999);
            $uri = 'urn:epc:id:sgtin:030116.0200116.'.$serial;
            $epc = Epc::query()->create(Epc::materializeAttributesFromUri($uri));
            $this->epcIds[] = (int) $epc->getKey();
            $this->authorTerminalDispositionEvent($epc);

            $barcode = '(01)'.$epc->gtin14.'(21)'.$epc->serial_number;
            $result = app(RunProductVerification::class)->handle($barcode);
            $verification = $result['verification'];
            $this->verificationIds[] = (int) $verification->getKey();

            $this->assertSame('failed', $verification->status);
            $this->assertNotSame('verified', $verification->status);
            $this->assertNull($verification->verified_at);
            $this->assertStringContainsString('cannot be verified', (string) $verification->message);

            $response = $verification->response_payload ?? [];
            $this->assertSame('decommissioned', $response['reason'] ?? null);
            $this->assertTrue((bool) ($verification->request_payload['l4']['terminal'] ?? false));
        } finally {
            $this->cleanup();
        }
    }

    private function authorTerminalDispositionEvent(Epc $epc): void
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'direction' => 'outbound',
            'status' => 'parsed',
            'original_filename' => 'vrs-outbound-terminal-'.Str::random(6).'.xml',
            'notes' => 'Terminal disposition for outbound VRS fail-closed test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $event = EpcisEvent::query()->create([
            'document_id' => $document->getKey(),
            'event_id' => 'urn:uuid:'.(string) Str::uuid(),
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'record_time' => now(),
            'event_timezone_offset' => '+00:00',
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:decommissioning',
            'disposition' => 'urn:epcglobal:cbv:disp:inactive',
            'read_point_gln' => '0366159000034',
            'biz_location_gln' => '0366159000034',
        ]);
        $this->eventIds[] = (int) $event->getKey();

        DB::table('event_epcs')->insertOrIgnore([[
            'event_id' => $event->getKey(),
            'epc_id' => $epc->getKey(),
            'role' => 'epcList',
        ]]);
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo 2',
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
        $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant === null) {
                return;
            }
            tenancy()->initialize($tenant);
        }

        if ($this->verificationIds !== []) {
            Verification::query()->whereIn('id', $this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        if ($this->eventIds !== []) {
            DB::table('event_epcs')->whereIn('event_id', $this->eventIds)->delete();
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->documentIds !== []) {
            $eventIds = EpcisEvent::query()
                ->whereIn('document_id', $this->documentIds)
                ->pluck('id')
                ->all();
            if ($eventIds !== []) {
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                EpcisEvent::query()->whereIn('id', $eventIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->epcIds !== []) {
            DB::table('event_epcs')->whereIn('epc_id', $this->epcIds)->delete();
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        tenancy()->end();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tenantApiPost(string $uri, ?string $token, array $data = []): TestResponse
    {
        $path = str_starts_with($uri, '/') ? $uri : '/'.$uri;
        $absolute = 'http://'.self::DEMO2_DOMAIN.$path;

        $server = [
            'HTTP_HOST' => self::DEMO2_DOMAIN,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        return $this->call(
            'POST',
            $absolute,
            [],
            [],
            [],
            $server,
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}

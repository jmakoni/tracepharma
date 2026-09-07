<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\PromoteOutboundConnectionConformance;
use App\Enums\OutboundConformanceState;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Models\ConnectionGoLiveChecklist;
use App\Models\Epcis\EpcisDocument;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Integrations\GoLiveChecklistEvaluator;
use App\Support\Integrations\GoLiveEvidencePack;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoLiveChecklistTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $checklistIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function promotion_to_live_is_blocked_until_checklist_complete(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $connection = $this->makeConnection(OutboundConformanceState::Hypercare);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Go-live checklist');

            app(PromoteOutboundConnectionConformance::class)->promoteOneStep($connection, $owner);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function completed_checklist_allows_promotion_to_live(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $connection = $this->makeConnection(OutboundConformanceState::Hypercare);

            $this->completeChecklist($connection, $owner);

            $this->assertTrue(app(GoLiveChecklistEvaluator::class)->isComplete($connection->fresh()));

            $promoted = app(PromoteOutboundConnectionConformance::class)->promoteOneStep($connection->fresh(), $owner);

            $this->assertSame(OutboundConformanceState::Live, $promoted->conformance_state);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function break_glass_records_reason_on_checklist(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $connection = $this->makeConnection(OutboundConformanceState::Test);

            app(PromoteOutboundConnectionConformance::class)->breakGlassToLive($connection, $owner, 'Partner audit deadline');

            $checklist = ConnectionGoLiveChecklist::query()
                ->where('connection_type', ConnectionGoLiveChecklist::TYPE_OUTBOUND)
                ->where('connection_id', $connection->getKey())
                ->first();

            $this->assertNotNull($checklist);
            $this->checklistIds[] = (int) $checklist->getKey();
            $this->assertSame('Partner audit deadline', $checklist->break_glass_reason);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function evidence_pack_renders_checklist_signoff_and_break_glass(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $owner = $this->createOwner();
            $connection = $this->makeConnection(OutboundConformanceState::Hypercare);
            $this->completeChecklist($connection, $owner);

            $tenant = tenant();
            $markdown = app(GoLiveEvidencePack::class)->render($tenant, $connection->fresh());

            $this->assertStringContainsString('# Go-live evidence pack', $markdown);
            $this->assertStringContainsString($connection->name, $markdown);
            $this->assertStringContainsString('[x] **Go-live sign-off**', $markdown);
            $this->assertStringContainsString('Signed off by: '.$owner->name, $markdown);
            $this->assertStringContainsString('Validated documents: 1', $markdown);

            $this->artisan('connections:go-live-report', [
                'tenant' => $tenant->getKey(),
                'connection' => $connection->getKey(),
                '--direction' => 'outbound',
            ])->expectsOutputToContain('# Go-live evidence pack')->assertSuccessful();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function evaluator_reports_incomplete_steps_for_fresh_connection(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $connection = $this->makeConnection(OutboundConformanceState::Test);

            $steps = app(GoLiveChecklistEvaluator::class)->evaluate($connection);
            $byKey = collect($steps)->keyBy('key');

            $this->assertTrue($byKey->get('profile_selected')['done']);
            $this->assertTrue($byKey->get('credentials_set')['done']);
            $this->assertFalse($byKey->get('probe_ok')['done']);
            $this->assertFalse($byKey->get('first_doc_validated')['done']);
            $this->assertTrue($byKey->get('exceptions_clear')['done']);
            $this->assertFalse($byKey->get('signed_off')['done']);
        } finally {
            $this->cleanup();
        }
    }

    private function makeConnection(OutboundConformanceState $state): OutboundConnection
    {
        $connection = OutboundConnection::query()->create([
            'name' => 'GoLive '.Str::random(6),
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
            'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
        ]);
        $this->connectionIds[] = (int) $connection->getKey();

        $connection->allowConformanceTransition = true;
        $connection->forceFill(['conformance_state' => $state->value])->save();
        $connection->allowConformanceTransition = false;

        return $connection->refresh();
    }

    private function completeChecklist(OutboundConnection $connection, User $owner): void
    {
        $settings = (array) $connection->settings;
        $settings['last_probe_ok'] = true;
        $settings['last_probe_at'] = now()->toIso8601String();
        $connection->forceFill(['settings' => $settings])->save();

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'golive-test.xml',
            'payload_disk' => 'local',
            'payload_path' => 'tests/golive-'.Str::random(6).'.xml',
            'file_sha256' => hash('sha256', 'golive'),
            'received_at' => now(),
            'dscsa_affirm' => false,
            'status' => 'validated',
            'outbound_connection_id' => $connection->getKey(),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $checklist = ConnectionGoLiveChecklist::forConnection(
            ConnectionGoLiveChecklist::TYPE_OUTBOUND,
            (int) $connection->getKey(),
        );
        $this->checklistIds[] = (int) $checklist->getKey();
        $checklist->signOff($owner);
    }

    private function createOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        $user = User::factory()->create([
            'email' => 'owner-golive-'.Str::uuid().'@example.com',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            if ($tenant->profile !== TenantProfile::DrugWholesaler) {
                $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            }
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->checklistIds !== []) {
            ConnectionGoLiveChecklist::query()->whereIn('id', $this->checklistIds)->delete();
            $this->checklistIds = [];
        }

        if ($this->connectionIds !== []) {
            OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            $this->connectionIds = [];
        }

        tenancy()->end();
    }
}

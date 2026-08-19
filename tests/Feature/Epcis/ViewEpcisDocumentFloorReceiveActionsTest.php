<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\EpcisDocuments\Pages\ViewEpcisDocument;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ViewEpcisDocumentFloorReceiveActionsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function validated_document_without_session_shows_start_receiving(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwner();
            $document = $this->makeValidatedDocument();

            Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('startReceiving')
                ->assertActionHasLabel('startReceiving', 'Start Receiving')
                ->assertActionHidden('viewReceivingSession')
                ->assertActionVisible('probeScan')
                ->assertActionVisible('reprocess');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function partial_session_shows_continue_receiving(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwner();
            $document = $this->makeValidatedDocument();
            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'status' => 'in_progress',
                'expected_parent_count' => 2,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 10,
                'confirmed_child_count' => 3,
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('startReceiving')
                ->assertActionHasLabel('startReceiving', 'Continue Receiving')
                ->assertActionVisible('viewReceivingSession')
                ->assertActionVisible('probeScan')
                ->assertActionVisible('reprocess');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function completed_receive_hides_start_probe_and_reprocess(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwner();
            $document = $this->makeValidatedDocument();
            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'status' => 'completed',
                'expected_parent_count' => 2,
                'confirmed_parent_count' => 2,
                'expected_child_count' => 10,
                'confirmed_child_count' => 10,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionIds[] = (int) $session->getKey();

            Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->assertSuccessful()
                ->assertActionHidden('startReceiving')
                ->assertActionVisible('viewReceivingSession')
                ->assertActionHasLabel('viewReceivingSession', 'View Receiving Session')
                ->assertActionHidden('probeScan')
                ->assertActionHidden('reprocess');
        } finally {
            $this->cleanup();
        }
    }

    private function makeValidatedDocument(): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'creation_date' => now(),
            'received_at' => now(),
            'received_via' => 'filament_upload',
            'status' => 'validated',
            'dscsa_affirm' => true,
            'event_count' => 0,
            'epc_count' => 0,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function actingAsOwner(): User
    {
        config(['tracepharma.regulatory_compliance.password_gate' => false]);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'inbound-floor-actions-'.uniqid('', true).'@example.test',
        ]);
        $this->userIds[] = (int) $user->getKey();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);

        return $user;
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->sessionIds !== []) {
            ReceivingSession::query()->whereIn('id', $this->sessionIds)->delete();
            $this->sessionIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        tenancy()->end();
    }
}

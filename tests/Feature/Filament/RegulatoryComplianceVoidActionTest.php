<?php

namespace Tests\Feature\Filament;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\EpcisDocuments\Pages\ViewEpcisDocument;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegulatoryComplianceVoidActionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function void_action_rejects_incorrect_password_when_gate_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwnerWithGateEnabled();

            $document = $this->makeErrorDocument();

            $component = Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->mountAction('voidDocument')
                ->fillForm(['reason' => 'Discarded bad ASN'])
                ->mountAction('submit')
                ->fillForm(['regulatory_password' => 'not-the-password'])
                ->callMountedAction();

            $this->assertSame('error', $document->fresh()->status);
            $errors = $component->instance()->getErrorBag()->toArray();
            $this->assertNotSame([], $errors, 'Expected action errors; bag was empty');
            $joined = json_encode($errors);
            $this->assertTrue(
                str_contains($joined, 'regulatory_password') || str_contains(strtolower($joined), 'password'),
                'Expected a password-related validation error. Errors: '.$joined,
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function void_action_succeeds_with_password_and_reason_when_gate_enabled(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwnerWithGateEnabled();

            $document = $this->makeErrorDocument();

            Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->mountAction('voidDocument')
                ->fillForm(['reason' => 'Discarded bad ASN'])
                ->mountAction('submit')
                ->fillForm(['regulatory_password' => 'password'])
                ->callMountedAction()
                ->assertHasNoActionErrors();

            $this->assertSame('voided', $document->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function void_action_rejects_parent_only_submit_without_password_confirm(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->actingAsOwnerWithGateEnabled();

            $document = $this->makeErrorDocument();

            $component = Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->callAction('voidDocument', [
                    'reason' => 'Discarded bad ASN',
                ]);

            $this->assertSame('error', $document->fresh()->status);
            $errors = $component->instance()->getErrorBag()->toArray();
            $this->assertNotSame([], $errors, 'Expected action errors; bag was empty');
        } finally {
            $this->cleanup();
        }
    }

    private function actingAsOwnerWithGateEnabled(): User
    {
        config(['tracepharma.regulatory_compliance.password_gate' => true]);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create();
        $this->userIds[] = (int) $user->getKey();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);

        return $user;
    }

    private function initializeDemo2Tenant(): void
    {
        if (! self::$demo2TenantReady) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant === null) {
                $tenant = Tenant::query()->create([
                    'id' => self::DEMO2_TENANT_ID,
                    'name' => 'Demo 2',
                    'profile' => TenantProfile::Pharmacy,
                ]);
                $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            }

            if ($tenant->getInternal('db_name') !== self::DEMO2_DATABASE) {
                $tenant->setInternal('db_name', self::DEMO2_DATABASE);
                $tenant->save();
            }

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize(self::DEMO2_TENANT_ID);
        $this->assertTrue(Schema::hasTable('epcis_documents'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeErrorDocument(array $overrides = []): EpcisDocument
    {
        $path = 'epcis/inbound/gate-void-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'received_via' => 'filament_upload',
            'format' => 'xml',
            'original_filename' => 'gate-void-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'error',
            'error_message' => 'fixture error for gate test',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ], $overrides));

        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function cleanup(): void
    {
        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}

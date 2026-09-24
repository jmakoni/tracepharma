<?php

namespace Tests\Feature\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ViewExceptionEpcisDocumentLinkTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $caseIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function inbound_linked_case_header_links_to_inbound_epcis_view(): void
    {
        $this->bootViewer();

        try {
            $document = $this->makeDocument('inbound');
            $case = $this->makeCase($document, ExceptionStatus::Cleared);

            $expected = EpcisDocumentResource::getUrl(
                'view',
                ['record' => $document],
                panel: 'app',
            );

            $component = Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('viewEpcisDocument');

            $this->assertSame($expected, $component->instance()->getAction('viewEpcisDocument')->getUrl());
            $this->assertStringContainsString('/inbound-epcis/'.$document->getKey(), $expected);
            $this->assertSame(ExceptionStatus::Cleared, $case->fresh()?->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_linked_case_header_links_to_outbound_epcis_view(): void
    {
        $this->bootViewer();

        try {
            $document = $this->makeDocument('outbound');
            $case = $this->makeCase($document, ExceptionStatus::Investigating);

            $expected = OutboundEpcisDocumentResource::getUrl(
                'view',
                ['record' => $document],
                panel: 'app',
            );

            $component = Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('viewEpcisDocument');

            $this->assertSame($expected, $component->instance()->getAction('viewEpcisDocument')->getUrl());
            $this->assertStringContainsString('/outbound-epcis/'.$document->getKey(), $expected);
            $this->assertStringNotContainsString('/inbound-epcis/', $expected);
            $this->assertSame(ExceptionStatus::Investigating, $case->fresh()?->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function case_with_neither_document_nor_signal_hides_the_epcis_link(): void
    {
        $this->bootViewer();

        try {
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->first()
                ?? ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'No document',
                'description' => 'Standalone case',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionHidden('viewEpcisDocument');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function signal_only_case_resolves_document_from_epcis_exception_id(): void
    {
        $this->bootViewer();

        try {
            $document = $this->makeDocument('inbound');
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->first()
                ?? ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Signal only',
                'description' => 'No document_id',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Resolved,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $signal = EpcisException::query()->create([
                'document_id' => $document->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => 'GTIN not found',
                'status' => 'open',
                'case_id' => $case->getKey(),
            ]);
            $case->logActivity(
                ExceptionActivityKind::System,
                null,
                'Opened from ingest signal #'.$signal->getKey().'.',
                ExceptionActivityVisibility::Internal,
                ['epcis_exception_id' => $signal->getKey()],
            );

            $expected = EpcisDocumentResource::getUrl(
                'view',
                ['record' => $document],
                panel: 'app',
            );

            $component = Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('viewEpcisDocument');

            $this->assertSame($expected, $component->instance()->getAction('viewEpcisDocument')->getUrl());
            $this->assertSame(ExceptionStatus::Resolved, $case->fresh()?->status);
        } finally {
            $this->cleanup();
        }
    }

    private function bootViewer(): void
    {
        $this->initializeDemo2Tenant();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create([
            'email' => 'exc-doc-link-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();
        $this->actingAs($user);
        ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');
    }

    private function makeDocument(string $direction): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => $direction,
            'format' => 'xml',
            'original_filename' => $direction.'-link.xml',
            'file_sha256' => hash('sha256', (string) Str::uuid()),
            'dscsa_affirm' => true,
            'status' => 'validated',
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now(),
            'received_via' => 'filament_upload',
            'ingest_generation' => 1,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function makeCase(EpcisDocument $document, ExceptionStatus $status): ExceptionCase
    {
        $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->first()
            ?? ExceptionTypeSeeder::ensure('UNKNOWN_GTIN');

        $case = ExceptionCase::query()->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => $document->getKey(),
            'title' => 'Linked '.$document->direction,
            'description' => 'Document link fixture',
            'severity' => ExceptionSeverity::High,
            'status' => $status,
        ]);
        $this->caseIds[] = (int) $case->getKey();

        return $case;
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

        if ($this->caseIds !== []) {
            DB::table('exception_activities')->whereIn('exception_id', $this->caseIds)->delete();
            EpcisException::query()->whereIn('case_id', $this->caseIds)->delete();
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
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

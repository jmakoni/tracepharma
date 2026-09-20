<?php

namespace Tests\Feature\Receiving;

use App\Enums\EpcisReceivedVia;
use App\Enums\ExceptionReceiveImpact;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileListReceivingSessions;
use App\Filament\App\Resources\ReceivingSessions\Pages\MobileListScanFirstSessions;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Receiving\EligibleEpcisReceiveDocuments;
use Database\Seeders\ExceptionTypeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FloorEpcisReceiveListTest extends TestCase
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
    private array $caseIds = [];

    #[Test]
    public function floor_epcis_receive_lists_ready_and_partial_files_only(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser();
            $this->actingAs($user);

            $suffix = uniqid('floor-epcis-', true);
            $readySeller = 'Ready Seller '.$suffix;
            $partialSeller = 'Partial Seller '.$suffix;
            $ready = $this->document('ready-'.$suffix.'.xml', $readySeller, 'ASN-'.$suffix);
            $partial = $this->document('partial-'.$suffix.'.xml', $partialSeller, null, 'PO-'.$suffix);
            $received = $this->document('received-'.$suffix.'.xml', 'Received Seller '.$suffix, 'ASN-R-'.$suffix);
            $blocked = $this->document('blocked-'.$suffix.'.xml', 'Blocked Seller '.$suffix, 'ASN-B-'.$suffix);

            $partialSession = ReceivingSession::query()->create([
                'epcis_document_id' => $partial->getKey(),
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'status' => 'in_progress',
                'expected_parent_count' => 2,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 4,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $partialSession->getKey();

            $receivedSession = ReceivingSession::query()->create([
                'epcis_document_id' => $received->getKey(),
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'status' => 'completed',
                'expected_parent_count' => 1,
                'confirmed_parent_count' => 1,
                'expected_child_count' => 1,
                'confirmed_child_count' => 1,
                'opened_at' => now(),
                'completed_at' => now(),
            ]);
            $this->sessionIds[] = (int) $receivedSession->getKey();

            $unknownGtin = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $unknownGtin->forceFill(['receive_impact' => ExceptionReceiveImpact::BusinessRule])->save();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $unknownGtin->getKey(),
                'document_id' => $blocked->getKey(),
                'title' => 'Unknown GTIN blocks receive',
                'description' => 'GTIN not found in product master',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $ids = app(EligibleEpcisReceiveDocuments::class)->list()->modelKeys();

            $this->assertContains($ready->getKey(), $ids);
            $this->assertContains($partial->getKey(), $ids);
            $this->assertNotContains($received->getKey(), $ids);
            $this->assertNotContains($blocked->getKey(), $ids);

            $rows = app(EligibleEpcisReceiveDocuments::class);
            $this->assertSame($readySeller, $rows->rowTitle($ready));
            $this->assertSame('ASN ASN-'.$suffix.' · Ready', $rows->rowMeta($ready));
            $this->assertSame($partialSeller, $rows->rowTitle($partial->fresh()));
            $this->assertSame('PO PO-'.$suffix.' · Partially Received', $rows->rowMeta($partial->fresh(['receivingSession'])));

            Livewire::test(MobileListReceivingSessions::class)
                ->assertSuccessful()
                ->assertSee('EPCIS Receive')
                ->assertSee($readySeller)
                ->assertSee('ASN ASN-'.$suffix)
                ->assertSee($partialSeller)
                ->assertSee('PO PO-'.$suffix)
                ->assertSee('Partially Received')
                ->assertDontSee('ready-'.$suffix.'.xml')
                ->assertDontSee('received-'.$suffix.'.xml')
                ->assertDontSee('blocked-'.$suffix.'.xml')
                ->assertDontSee('New scan-first');

            $scanFirst = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::ScanFirst,
                'status' => 'open',
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $scanFirst->getKey();

            Livewire::test(MobileListScanFirstSessions::class)
                ->assertSuccessful()
                ->assertSee('Scan first')
                ->assertSee('New scan-first')
                ->assertSee('#'.$scanFirst->getKey())
                ->assertDontSee('ready-'.$suffix.'.xml');
        } finally {
            $this->cleanup();
        }
    }

    private function document(string $filename, string $seller, ?string $asn = null, ?string $po = null): EpcisDocument
    {
        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'direction' => 'inbound',
            'creation_date' => now(),
            'received_at' => now(),
            'status' => 'validated',
            'dscsa_affirm' => true,
            'received_via' => EpcisReceivedVia::FilamentUpload,
            'original_filename' => $filename,
            'ship_from_name' => $seller,
            'asn_number' => $asn,
            'customer_po' => $po,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function createOwnerUser(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

        $user = User::factory()->create([
            'email' => 'floor-epcis-'.uniqid('', true).'@example.test',
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
        (new ExceptionTypeSeeder)->run();

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

        if ($this->caseIds !== []) {
            ExceptionCase::query()->whereIn('id', $this->caseIds)->delete();
            $this->caseIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        tenancy()->end();
    }
}

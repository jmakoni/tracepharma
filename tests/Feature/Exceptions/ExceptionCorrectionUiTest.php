<?php

namespace Tests\Feature\Exceptions;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\Exceptions\Pages\ViewException;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionAction;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Exceptions\ExceptionType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\Receiving\ReceivingSession;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use Database\Seeders\ExceptionCaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExceptionCorrectionUiTest extends TestCase
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

    /** @var list<int> */
    private array $tenantPartnerIds = [];

    /** @var list<int> */
    private array $tenantProductIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    #[Test]
    public function correction_profile_maps_unknown_gtin_and_ingestion_parse_error(): void
    {
        $gtinProfile = ExceptionCorrectionProfile::for('UNKNOWN_GTIN');
        $this->assertSame(ExceptionCorrectionProfile::FAMILY_MASTER_DATA_PRODUCT, $gtinProfile->family());
        $this->assertSame(ExceptionCorrectionProfile::ACTION_ADD_PRODUCT, $gtinProfile->primaryActionKey());
        $this->assertTrue($gtinProfile->showsMasterDataProductForm());
        $this->assertTrue($gtinProfile->isSpecialized());
        $this->assertSame('Authorize product', $gtinProfile->primaryActionLabel());

        $parseErrorProfile = ExceptionCorrectionProfile::for('INGESTION_PARSE_ERROR');
        $this->assertSame(ExceptionCorrectionProfile::FAMILY_DOCUMENT, $parseErrorProfile->family());
        $this->assertTrue($parseErrorProfile->showsDocumentTools());
        $this->assertSame(ExceptionCorrectionProfile::ACTION_FIX_DOCUMENT, $parseErrorProfile->primaryActionKey());

        $unclassifiedProfile = ExceptionCorrectionProfile::for('UNCLASSIFIED');
        $this->assertFalse($unclassifiedProfile->isSpecialized());

        $this->assertSame(
            '30301164005087',
            ExceptionCorrectionProfile::extractGtinFromDescription('GTIN not found in product master: 30301164005087'),
        );

        $internalValidation = ExceptionCorrectionProfile::for('INTERNAL_VALIDATION_FAILED');
        $this->assertTrue($internalValidation->showsDocumentTools());
        $this->assertFalse($internalValidation->showsWaive());
    }

    #[Test]
    public function view_exception_shows_add_product_action_for_unknown_gtin(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $gtin = $this->uniqueGtin();
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('addProductToAssortment')
                ->assertSee('Authorize product')
                ->assertSee('Suggested correction');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function open_unknown_gtin_shows_suggested_root_cause_and_resolve_defaults(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $gtin = $this->uniqueGtin();
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $this->assertNull($case->fresh()->root_cause_id);

            $rootCause = ExceptionRootCause::query()->where('code', 'internal_mapping_error')->firstOrFail();
            $resolutionAction = ExceptionAction::query()->where('code', 'update_master_data')->firstOrFail();

            $component = Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertSee('Suggested root cause')
                ->assertSee('Internal mapping / master data error')
                ->assertSee('Suggested resolution action')
                ->assertSee('Update master data')
                ->assertActionVisible('addProductToAssortment')
                ->assertActionVisible('resolve')
                ->mountAction('resolve');

            $this->assertNull($case->fresh()->root_cause_id);

            /** @var ViewException $page */
            $page = $component->instance();

            $schemaMethod = new \ReflectionMethod($page, 'getMountedActionSchema');
            $schemaMethod->setAccessible(true);
            $schema = $schemaMethod->invoke($page, mountedAction: $page->getMountedAction());
            $components = collect($schema->getFlatComponents(withHidden: true));

            $rootCauseSelect = $components->first(fn ($component): bool => $component->getName() === 'root_cause_id');
            $actionSelect = $components->first(fn ($component): bool => $component->getName() === 'resolution_action_id');

            $this->assertNotNull($rootCauseSelect);
            $this->assertNotNull($actionSelect);
            $this->assertSame((int) $rootCause->getKey(), (int) $rootCauseSelect->getDefaultState());
            $this->assertSame((int) $resolutionAction->getKey(), (int) $actionSelect->getDefaultState());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function exception_header_shows_primary_actions_and_actions_menu(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $gtin = $this->uniqueGtin();
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertSee('Assign To Me')
                ->assertSee('Authorize Product')
                ->assertSee('Quarantine')
                ->assertSee('More')
                ->assertActionVisible('addProductToAssortment')
                ->assertActionVisible('assignToMe')
                ->assertActionVisible('escalate')
                ->assertActionVisible('changeStatus')
                ->assertActionVisible('resolve');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function triaged_unclassified_unknown_gtin_shows_add_product_and_resolve(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $gtin = $this->uniqueGtin();
            $unclassified = ExceptionType::query()->firstOrCreate(
                ['code' => 'UNCLASSIFIED'],
                [
                    'name' => 'Unclassified',
                    'category' => 'system',
                    'description' => 'Fallback type for unmapped ingest signals.',
                    'default_severity' => ExceptionSeverity::Medium,
                    'is_active' => true,
                ],
            );

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $unclassified->getKey(),
                'title' => 'Unclassified · Document #1',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::Medium,
                'status' => ExceptionStatus::Triaged,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            EpcisException::query()->create([
                'case_id' => $case->getKey(),
                'exception_type' => 'UNKNOWN_GTIN',
                'severity' => 'error',
                'description' => "GTIN not found in product master: {$gtin}",
                'status' => 'open',
            ]);

            $this->assertSame(
                ExceptionCorrectionProfile::FAMILY_MASTER_DATA_PRODUCT,
                ExceptionCorrectionProfile::forCase($case->fresh(['type', 'signals']))->family(),
            );

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('addProductToAssortment')
                ->assertActionVisible('resolve');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function change_status_excludes_resolved_and_closed_from_picker(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Investigating case',
                'description' => 'Status picker must not bypass resolve workflow.',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            $component = Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('changeStatus')
                ->assertActionVisible('resolve')
                ->mountAction('changeStatus');

            /** @var ViewException $page */
            $page = $component->instance();

            $schemaMethod = new \ReflectionMethod($page, 'getMountedActionSchema');
            $schemaMethod->setAccessible(true);
            $schema = $schemaMethod->invoke($page, mountedAction: $page->getMountedAction());
            $statusSelect = collect($schema->getFlatComponents(withHidden: true))
                ->first(fn ($component): bool => $component->getName() === 'status');

            $this->assertNotNull($statusSelect);
            $options = array_keys($statusSelect->getOptions());
            $this->assertNotContains(ExceptionStatus::Resolved->value, $options);
            $this->assertNotContains(ExceptionStatus::Closed->value, $options);
            $this->assertContains(ExceptionStatus::WaitingPartner->value, $options);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function change_status_rejects_crafted_resolved_status(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Investigating case',
                'description' => 'Crafted status must not bypass resolve workflow.',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('changeStatus', [
                    'status' => ExceptionStatus::Resolved->value,
                    'notes' => 'Bypass attempt',
                ])
                ->assertHasActionErrors(['status']);

            $this->assertSame(ExceptionStatus::Investigating, $case->fresh()->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolved_case_hides_open_workflow_actions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $gtin = $this->uniqueGtin();
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Resolved,
                'assigned_to' => $user->getKey(),
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionHidden('addProductToAssortment')
                ->assertActionHidden('assignToMe')
                ->assertActionHidden('resolve')
                ->assertActionVisible('close');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function view_exception_shows_document_tools_for_ingestion_parse_error(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $document = $this->makeErrorDocument();
            $type = ExceptionType::query()->where('code', 'INGESTION_PARSE_ERROR')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Ingestion parse error',
                'description' => 'XML/JSON could not be parsed for this document.',
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionVisible('reprocessDocument')
                ->assertSee('Fix or replace document')
                ->assertActionHidden('addProductToAssortment');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function fda_miss_shows_fda_products_guidance_without_freeform_invent(): void
    {
        $gtin = $this->uniqueGtin();

        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertNull(AssortmentFromCatalog::findPackagingByGtin($gtin));

            $fdaUrl = AssortmentFromCatalog::fdaProductsUrl($gtin);
            $this->assertStringContainsString('search=', $fdaUrl);
            $this->assertStringContainsString(urlencode($gtin), $fdaUrl);
            $this->assertStringContainsString(
                'do not invent a freeform product record',
                AssortmentFromCatalog::catalogMissMessage(),
            );

            $user = $this->actingAsExceptionViewer();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertActionVisible('addProductToAssortment');

            $this->assertFalse(Product::query()->where('gtin', $gtin)->exists());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function fda_hit_authorizes_product_for_trading_partner(): void
    {
        $gtin = $this->uniqueGtin();
        $packaging = $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $wholesaler = TradingPartner::query()->create([
                'name' => 'Exception UI Wholesaler '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $wholesaler->getKey();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $gtin,
                    'trading_partner_id' => $wholesaler->getKey(),
                    'also_resolve' => false,
                    'also_reprocess' => false,
                    'resolution_notes' => 'Authorized missing GTIN from catalog.',
                ])
                ->assertHasNoActionErrors();

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->first();
            $this->assertNotNull($product);
            $this->tenantProductIds[] = (int) $product->getKey();
            $this->assertSame($gtin, $product->gtin);
            $this->assertTrue($wholesaler->products()->where('products.id', $product->id)->exists());

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionHidden('addProductToAssortment')
                ->assertSee('This GTIN is now in product master');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function authorize_product_hidden_when_gtin_already_in_product_master(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $product = Product::query()->create([
                'gtin' => $gtin,
                'name' => 'Already authorized Rx '.uniqid(),
                'is_active' => true,
            ]);
            $this->tenantProductIds[] = (int) $product->getKey();

            $this->assertTrue(AssortmentFromCatalog::productAuthorizedForGtin($gtin));

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();
            $document = $this->makeErrorDocument();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionHidden('addProductToAssortment')
                ->assertSee('Re-process the linked document to close the case');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function authorize_with_active_receiving_leaves_case_open_and_hides_authorize(): void
    {
        $gtin = $this->uniqueGtin();
        $packaging = $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        $sessionId = null;

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $wholesaler = TradingPartner::query()->create([
                'name' => 'Exception UI Receiving Block Wholesaler '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $wholesaler->getKey();

            $document = $this->makeErrorDocument();
            $session = ReceivingSession::query()->create([
                'epcis_document_id' => $document->getKey(),
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $sessionId = (int) $session->getKey();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $gtin,
                    'trading_partner_id' => $wholesaler->getKey(),
                    'also_resolve' => true,
                    'also_reprocess' => true,
                    'resolution_notes' => 'Authorized missing GTIN from catalog.',
                ])
                ->assertHasNoActionErrors();

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->first();
            $this->assertNotNull($product);
            $this->tenantProductIds[] = (int) $product->getKey();

            $this->assertSame(ExceptionStatus::Investigating, $case->fresh()->status);

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertSuccessful()
                ->assertActionHidden('addProductToAssortment');
        } finally {
            if (tenancy()->initialized && $sessionId !== null) {
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }
            $this->cleanup();
        }
    }

    #[Test]
    public function find_packaging_by_gtin_reverses_ndc_encoded_gtin_via_package_ndc(): void
    {
        // FDA packaging stores indicator-0 GTIN; scanned GTIN uses indicator 3. Both encode
        // the same NDC10, which reverses to the package NDC (4-4-2 dashed form).
        $pair = $this->uniqueReversedGtinPair();
        $packaging = $this->createRxPackagingWithPackageNdc(
            gtin: $pair['catalog_gtin'],
            packageNdc: $pair['package_ndc'],
        );

        try {
            $found = AssortmentFromCatalog::findPackagingByGtin($pair['scanned_gtin']);

            $this->assertNotNull($found);
            $this->assertSame($packaging->id, $found->id);

            $summary = AssortmentFromCatalog::formatCatalogMatch($found, $pair['scanned_gtin']);
            $this->assertStringContainsString('matched via package NDC', $summary);

            // An exact-gtin match should not carry the reversed-match note.
            $exactSummary = AssortmentFromCatalog::formatCatalogMatch($found, $pair['catalog_gtin']);
            $this->assertStringNotContainsString('matched via package NDC', $exactSummary);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function fda_hit_via_reversed_gtin_authorizes_product_with_scanned_gtin(): void
    {
        $pair = $this->uniqueReversedGtinPair();
        $packaging = $this->createRxPackagingWithPackageNdc(
            gtin: $pair['catalog_gtin'],
            packageNdc: $pair['package_ndc'],
        );

        $searchedGtin = $pair['scanned_gtin'];

        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $wholesaler = TradingPartner::query()->create([
                'name' => 'Exception UI Reverse GTIN Wholesaler '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $wholesaler->getKey();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$searchedGtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $searchedGtin,
                    'trading_partner_id' => $wholesaler->getKey(),
                    'also_resolve' => false,
                    'also_reprocess' => false,
                    'resolution_notes' => 'Authorized missing GTIN from catalog via reversed NDC.',
                ])
                ->assertHasNoActionErrors();

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->first();
            $this->assertNotNull($product);
            $this->tenantProductIds[] = (int) $product->getKey();

            // The product should carry the GTIN that was actually scanned/searched, not
            // the packaging's own indicator-0 GTIN.
            $this->assertSame($searchedGtin, $product->gtin);
            $this->assertSame($pair['ndc11'], $product->ndc11);
            $this->assertTrue($wholesaler->products()->where('products.id', $product->id)->exists());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function unlinked_manufacturer_matching_catalog_labeler_is_auto_linked_on_authorize(): void
    {
        $gtin = $this->uniqueGtin();
        $labelerName = 'Exception UI Labeler Mfg '.uniqid();

        $org = FdaOrganization::query()->create([
            'original_name' => $labelerName,
            'canonical_name' => strtoupper($labelerName),
            'name' => $labelerName,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $listing = FdaProduct::query()->create([
            'product_id' => 'TEST-EXC-UI-LINK-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'Auto-link Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->productIds[] = (int) $listing->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'gtin' => $gtin,
            'package_ndc' => fake()->unique()->numerify('#####-###-##'),
            'ndc11' => fake()->unique()->numerify('###########'),
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $manufacturer = TradingPartner::query()->create([
                'name' => $labelerName,
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Manufacturer,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $manufacturer->getKey();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $gtin,
                    'trading_partner_id' => $manufacturer->getKey(),
                    'also_resolve' => false,
                    'also_reprocess' => false,
                    'resolution_notes' => 'Authorized missing GTIN from catalog.',
                ])
                ->assertHasNoActionErrors();

            $manufacturer->refresh();
            $this->assertSame((int) $org->getKey(), (int) $manufacturer->fda_organization_id);

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->first();
            $this->assertNotNull($product);
            $this->tenantProductIds[] = (int) $product->getKey();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function authorize_with_document_requires_reprocess_before_auto_resolve(): void
    {
        $gtin = $this->uniqueGtin();
        $packaging = $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $wholesaler = TradingPartner::query()->create([
                'name' => 'Exception UI Reprocess Gate Wholesaler '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $wholesaler->getKey();

            $document = $this->makeErrorDocument();
            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $gtin,
                    'trading_partner_id' => $wholesaler->getKey(),
                    'also_resolve' => true,
                    'also_reprocess' => false,
                    'resolution_notes' => 'Authorized missing GTIN from catalog.',
                ])
                ->assertHasNoActionErrors();

            $product = Product::query()->where('fda_product_packaging_id', $packaging->id)->first();
            $this->assertNotNull($product);
            $this->tenantProductIds[] = (int) $product->getKey();

            $this->assertSame(ExceptionStatus::Investigating, $case->fresh()->status);
            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->assertActionHidden('addProductToAssortment');
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function authorize_with_also_resolve_seeds_missing_resolution_catalog(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        try {
            ExceptionRootCause::query()->delete();
            ExceptionAction::query()->delete();

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->actingAsExceptionViewer();

            $wholesaler = TradingPartner::query()->create([
                'name' => 'Exception UI Resolve Catalog Wholesaler '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $wholesaler->getKey();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::Investigating,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $gtin,
                    'trading_partner_id' => $wholesaler->getKey(),
                    'also_resolve' => true,
                    'also_reprocess' => false,
                    'resolution_notes' => 'Authorized missing GTIN from catalog.',
                ])
                ->assertHasNoActionErrors();

            $this->assertSame(ExceptionStatus::Resolved, $case->fresh()->status);
            $this->assertNotNull(ExceptionRootCause::query()->where('code', 'internal_mapping_error')->value('id'));
            $this->assertNotNull(ExceptionAction::query()->where('code', 'update_master_data')->value('id'));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function regulatory_compliance_gate_is_enforced_when_adding_product_from_exception(): void
    {
        $gtin = $this->uniqueGtin();
        $this->createRxPackaging($gtin);

        $this->initializeDemo2Tenant();

        try {
            config(['tracepharma.regulatory_compliance.password_gate' => true]);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $user = $this->actingAsExceptionViewer();

            $wholesaler = TradingPartner::query()->create([
                'name' => 'Exception UI Wholesaler '.uniqid(),
                'gln' => fake()->unique()->numerify('#############'),
                'partner_type' => PartnerType::Wholesaler,
                'country_code' => 'US',
                'is_active' => true,
            ]);
            $this->tenantPartnerIds[] = (int) $wholesaler->getKey();

            $type = ExceptionType::query()->where('code', 'UNKNOWN_GTIN')->firstOrFail();

            $case = ExceptionCase::query()->create([
                'exception_type_id' => $type->getKey(),
                'title' => 'Unknown GTIN encountered',
                'description' => "GTIN not found in product master: {$gtin}",
                'severity' => ExceptionSeverity::High,
                'status' => ExceptionStatus::New,
            ]);
            $this->caseIds[] = (int) $case->getKey();

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $gtin,
                    'trading_partner_id' => $wholesaler->getKey(),
                    'also_resolve' => false,
                    'also_reprocess' => false,
                    'resolution_notes' => 'Authorized missing GTIN from catalog.',
                    'regulatory_password' => 'not-the-password',
                ])
                ->assertHasActionErrors(['regulatory_password' => 'The password you entered is incorrect.']);

            $this->assertFalse(Product::query()->where('gtin', $gtin)->exists(), 'Product should not be created with an incorrect password.');

            Livewire::test(ViewException::class, ['record' => $case->getKey()])
                ->callAction('addProductToAssortment', [
                    'gtin' => $gtin,
                    'trading_partner_id' => $wholesaler->getKey(),
                    'also_resolve' => false,
                    'also_reprocess' => false,
                    'resolution_notes' => 'Authorized missing GTIN from catalog.',
                    'regulatory_password' => 'password',
                ])
                ->assertHasNoActionErrors();

            $product = Product::query()->where('gtin', $gtin)->first();
            $this->assertNotNull($product, 'Product should be created once the correct password is supplied.');
            $this->tenantProductIds[] = (int) $product->getKey();
            $this->assertNotNull($product->fda_product_packaging_id);
            $this->assertSame(ExceptionStatus::New, $case->fresh()->status, 'Case status should be unchanged since also_resolve was false.');
        } finally {
            $this->cleanup();
        }
    }

    private function actingAsExceptionViewer(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();
        $this->actingAs($user);

        return $user;
    }

    private function uniqueGtin(): string
    {
        return '030116'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Unique 4-4-2 package NDC + indicator-0/3 NDC-encoded GTIN pair (avoids demo seed collision).
     *
     * @return array{package_ndc: string, catalog_gtin: string, scanned_gtin: string, ndc11: string}
     */
    private function uniqueReversedGtinPair(): array
    {
        do {
            $packageNdc = sprintf(
                '%04d-%04d-%02d',
                random_int(1000, 9999),
                random_int(0, 9999),
                random_int(0, 99),
            );
            $ndc10 = preg_replace('/\D+/', '', $packageNdc) ?? '';
            $ndc11 = Ndc::toNdc11($packageNdc);
            $catalogBody = '003'.$ndc10;
            $scannedBody = '303'.$ndc10;
            $catalogGtin = $catalogBody.Gtin::checkDigit($catalogBody);
            $scannedGtin = $scannedBody.Gtin::checkDigit($scannedBody);
        } while (
            $ndc11 === null
            || strlen($ndc10) !== 10
            || FdaProductPackaging::query()
                ->where(function ($q) use ($packageNdc, $catalogGtin, $scannedGtin): void {
                    $q->where('package_ndc', $packageNdc)
                        ->orWhereIn('gtin', [$catalogGtin, $scannedGtin]);
                })
                ->exists()
        );

        return [
            'package_ndc' => $packageNdc,
            'catalog_gtin' => $catalogGtin,
            'scanned_gtin' => $scannedGtin,
            'ndc11' => $ndc11,
        ];
    }

    private function createRxPackaging(string $gtin): FdaProductPackaging
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT Exc UI '.uniqid(),
            'canonical_name' => 'SSOR CUT EXC UI '.uniqid(),
            'name' => 'SSOR CUT Exc UI '.uniqid(),
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $listing = FdaProduct::query()->create([
            'product_id' => 'TEST-EXC-UI-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'Exception UI Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->productIds[] = (int) $listing->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'gtin' => $gtin,
            'package_ndc' => fake()->unique()->numerify('#####-###-##'),
            'ndc11' => fake()->unique()->numerify('###########'),
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        return $packaging;
    }

    private function createRxPackagingWithPackageNdc(string $gtin, string $packageNdc): FdaProductPackaging
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR CUT Exc UI Rev '.uniqid(),
            'canonical_name' => 'SSOR CUT EXC UI REV '.uniqid(),
            'name' => 'SSOR CUT Exc UI Rev '.uniqid(),
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->getKey();

        $listing = FdaProduct::query()->create([
            'product_id' => 'TEST-EXC-UI-REV-'.uniqid(),
            'product_ndc' => fake()->unique()->numerify('#####-###'),
            'brand_name' => 'Exception UI Reverse GTIN Rx',
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'fda_organization_id' => $org->id,
            'finished' => true,
            'is_active' => true,
        ]);
        $this->productIds[] = (int) $listing->getKey();

        $packaging = FdaProductPackaging::query()->create([
            'fda_product_id' => $listing->id,
            'gtin' => $gtin,
            'package_ndc' => $packageNdc,
            'ndc11' => Ndc::toNdc11($packageNdc),
            'is_active' => true,
        ]);
        $this->packagingIds[] = (int) $packaging->getKey();

        return $packaging;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeErrorDocument(array $overrides = []): EpcisDocument
    {
        $path = 'epcis/inbound/correction-ui-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'correction-ui-test.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'error',
            'error_message' => 'fixture error for correction UI test',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ], $overrides));

        $this->documentIds[] = (int) $document->getKey();

        return $document;
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

        $this->seed(ExceptionCaseSeeder::class);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            foreach ($this->caseIds as $id) {
                $case = ExceptionCase::query()->find($id);
                if ($case === null) {
                    continue;
                }
                $case->activities()->delete();
                $case->epcs()->detach();
                $case->delete();
            }

            foreach ($this->documentIds as $id) {
                EpcisDocument::query()->whereKey($id)->delete();
            }

            foreach ($this->tenantProductIds as $id) {
                $product = Product::query()->find($id);
                if ($product === null) {
                    continue;
                }
                $product->tradingPartners()->detach();
                $product->delete();
            }

            if ($this->tenantPartnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->tenantPartnerIds)->delete();
            }

            if ($this->userIds !== []) {
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            $this->caseIds = [];
            $this->documentIds = [];
            $this->userIds = [];
            $this->tenantPartnerIds = [];
            $this->tenantProductIds = [];

            tenancy()->end();
        }

        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
            $this->packagingIds = [];
        }

        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
            $this->productIds = [];
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\HubReceiverGlnClaimRequestStatus;
use App\Enums\TenantProfile;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\RelationManagers\HubReceiverGlnClaimRequestsRelationManager;
use App\Filament\Admin\Resources\Tenants\RelationManagers\HubReceiverGlnClaimsRelationManager;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Admin;
use App\Models\EpcisHubRoute;
use App\Models\HubReceiverGlnClaimRequest;
use App\Models\Tenant;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HubReceiverGlnClaimRequestsRelationManagerTest extends TestCase
{
    private const GLN = '0366159000010';

    private ?Tenant $tenant = null;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        app(EpcisHubPlatformConfig::class)->setProviders('stage', ['systech']);

        $this->tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::query()->create([
            'id' => (string) str()->uuid(),
            'name' => 'Hub claim review UI test',
            'profile' => TenantProfile::Pharmacy,
            'status' => 'active',
            'gln' => self::GLN,
            'inbound_environment' => 'stage',
            'hub_providers' => ['systech'],
            'tenancy_db_name' => 'tenant_hub_claim_review_ui_test',
        ]));

        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = Admin::factory()->create();
        $this->admin->assignRole(AdminRole::PlatformAdmin->value);

        $this->actingAs($this->admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        if ($this->tenant !== null) {
            HubReceiverGlnClaimRequest::query()->where('tenant_id', $this->tenant->getKey())->delete();
            EpcisHubRoute::query()->where('tenant_id', $this->tenant->getKey())->delete();
            Tenant::withoutEvents(fn () => $this->tenant->delete());
        }

        if ($this->admin !== null) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->where('model_id', $this->admin->getKey())
                ->delete();
            $this->admin->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function claim_requests_are_registered_next_to_claims_and_only_pending_requests_are_listed(): void
    {
        $relations = TenantResource::getRelations();
        $claimsIndex = array_search(
            HubReceiverGlnClaimsRelationManager::class,
            $relations,
            true,
        );
        $requestsIndex = array_search(HubReceiverGlnClaimRequestsRelationManager::class, $relations, true);

        $this->assertNotFalse($claimsIndex);
        $this->assertSame($claimsIndex + 1, $requestsIndex);

        $pending = $this->pendingRequest();
        $reviewed = $this->pendingRequest('unitrace', '0366159000027');
        $reviewed->forceFill(['status' => HubReceiverGlnClaimRequestStatus::Rejected])->save();

        $this->relationManager()
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$reviewed])
            ->assertTableColumnExists('gln')
            ->assertTableColumnExists('provider')
            ->assertTableColumnExists('reason')
            ->assertTableColumnExists('requested_by')
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('created_at');
    }

    #[Test]
    public function platform_admin_can_approve_a_pending_request_from_the_tenant_relation_manager(): void
    {
        $request = $this->pendingRequest();

        $this->relationManager()
            ->assertActionVisible(TestAction::make('approve')->table($request))
            ->callAction(TestAction::make('approve')->table($request))
            ->assertHasNoActionErrors();

        $this->assertSame(HubReceiverGlnClaimRequestStatus::Approved, $request->fresh()?->status);
        $this->assertDatabaseHas('epcis_hub_routes', [
            'tenant_id' => $this->tenant?->getKey(),
            'provider' => 'systech',
            'gln' => self::GLN,
            'claimed_via' => EpcisHubRoute::CLAIMED_VIA_ADMIN,
        ]);
    }

    #[Test]
    public function platform_admin_can_reject_a_pending_request_with_an_optional_note(): void
    {
        $request = $this->pendingRequest();

        $this->relationManager()
            ->callAction(
                TestAction::make('reject')->table($request),
                ['review_note' => 'Ownership evidence did not match.'],
            )
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame(HubReceiverGlnClaimRequestStatus::Rejected, $request->status);
        $this->assertSame('Ownership evidence did not match.', $request->review_note);
        $this->assertFalse(EpcisHubRoute::query()->where('tenant_id', $this->tenant?->getKey())->exists());
    }

    private function pendingRequest(
        string $provider = 'systech',
        string $gln = self::GLN,
    ): HubReceiverGlnClaimRequest {
        return HubReceiverGlnClaimRequest::query()->create([
            'tenant_id' => $this->tenant?->getKey(),
            'provider' => $provider,
            'gln' => $gln,
            'reason' => 'Enable hub receiving.',
            'requested_by' => 'Tenant Owner (owner@example.com)',
            'status' => HubReceiverGlnClaimRequestStatus::Pending,
        ]);
    }

    private function relationManager(): Testable
    {
        return Livewire::test(HubReceiverGlnClaimRequestsRelationManager::class, [
            'ownerRecord' => $this->tenant,
            'pageClass' => EditTenant::class,
        ]);
    }
}

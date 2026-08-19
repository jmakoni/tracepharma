<?php

namespace Tests\Feature\MasterData;

use App\Enums\PartnerType;
use App\Enums\SsccNumberRangeScope;
use App\Enums\SsccNumberRangeStatus;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\TradingPartners\Pages\ListTradingPartners;
use App\Filament\App\Resources\TradingPartners\Tables\TradingPartnersTable;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Policies\TradingPartnerPolicy;
use App\Services\Quarantine\SupplierPortalService;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\MasterData\TradingPartnerReferences;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Exceptions\Cancel;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TradingPartnerDeleteGuardTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function unreferenced_partner_can_be_hard_deleted(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner();

            $this->assertFalse(TradingPartnerReferences::isReferenced($partner));
            $this->assertNull(TradingPartnerReferences::summary($partner));

            $partner->delete();

            $this->assertNull(TradingPartner::query()->find($partner->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function referenced_partner_delete_is_blocked_and_names_the_reference(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner();
            $this->createProductFor($partner);

            $this->assertTrue(TradingPartnerReferences::isReferenced($partner));
            $this->assertSame('1 labeled product', TradingPartnerReferences::summary($partner));

            try {
                $partner->delete();
                $this->fail('Deleting a referenced trading partner must throw.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('1 labeled product', $exception->getMessage());
                $this->assertStringContainsString('Deactivate', $exception->getMessage());
            }

            $this->assertNotNull(TradingPartner::query()->find($partner->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function only_sscc_ranges_that_issued_serials_block_delete(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner();
            $rangeId = $this->createSsccRangeFor($partner, issued: false);

            $this->assertFalse(TradingPartnerReferences::isReferenced($partner));

            DB::table('sscc_number_ranges')
                ->where('id', $rangeId)
                ->update(['current_number' => 5000]);

            $this->assertTrue(TradingPartnerReferences::isReferenced($partner));
            $this->assertSame('1 issued SSCC number range', TradingPartnerReferences::summary($partner));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_policy_requires_a_master_data_role_and_zero_references(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);

            $owner = $this->createUserWithRole(TenantRole::Owner);
            $technician = $this->createUserWithRole(TenantRole::ReceivingTechnician);

            $partner = $this->createPartner();
            $policy = new TradingPartnerPolicy;

            $this->assertTrue($policy->viewAny($technician));
            $this->assertTrue($policy->view($technician, $partner));
            $this->assertFalse($policy->create($technician));
            $this->assertFalse($policy->update($technician, $partner));
            $this->assertFalse($policy->delete($technician, $partner));

            $this->assertTrue($policy->create($owner));
            $this->assertTrue($policy->update($owner, $partner));
            $this->assertTrue($policy->delete($owner, $partner));

            $this->createProductFor($partner);

            $this->assertTrue(
                $policy->deleteAny($owner),
                'Bulk delete stays available; individual records are filtered by the delete ability.',
            );
            $this->assertFalse(
                $policy->delete($owner, $partner->fresh()),
                'A referenced partner must not be deletable, even by the tenant owner.',
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function delete_action_cancels_with_a_notification_when_the_partner_is_referenced(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner();
            $deleteAction = $this->recordAction('delete', $partner);
            $this->assertInstanceOf(DeleteAction::class, $deleteAction);

            $deleteAction->callBefore();

            $this->createProductFor($partner);

            $deleteAction->record($partner->fresh());

            try {
                $deleteAction->callBefore();
                $this->fail('The delete action must cancel when the partner is still referenced.');
            } catch (Cancel) {
                $notifications = collect(session()->get('filament.notifications', []));

                $this->assertTrue(
                    $notifications->contains(fn (array $notification): bool => ($notification['title'] ?? null) === 'Trading partner cannot be deleted'),
                    'The blocked delete must explain itself with a notification.',
                );
            }

            $this->assertNotNull(TradingPartner::query()->find($partner->getKey()));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function table_offers_deactivate_and_activate_alongside_the_gated_delete(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $this->actingAs($this->createUserWithRole(TenantRole::Owner));

            $active = $this->createPartner();
            $inactive = $this->createPartner(['is_active' => false]);

            $this->assertTrue($this->recordAction('deactivate', $active)->isVisible());
            $this->assertFalse($this->recordAction('deactivate', $inactive)->isVisible());
            $this->assertTrue($this->recordAction('activate', $inactive)->isVisible());
            $this->assertFalse($this->recordAction('activate', $active)->isVisible());

            $this->recordAction('deactivate', $active)->call();

            $this->assertFalse((bool) $active->fresh()->is_active);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function portal_link_actions_appear_once_a_link_exists_and_rotate_or_revoke_it(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $this->actingAs($this->createUserWithRole(TenantRole::Owner));

            $partner = $this->createPartner();

            $this->assertFalse(
                $this->recordAction('rotatePortalLink', $partner)->isVisible(),
                'There is nothing to rotate before a portal link has been shared.',
            );
            $this->assertFalse($this->recordAction('revokePortalLink', $partner)->isVisible());

            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);
            $partner->refresh();
            $original = $partner->portal_share_uuid;
            $this->assertNotNull($original);

            $this->assertTrue($this->recordAction('rotatePortalLink', $partner)->isVisible());
            $this->assertTrue($this->recordAction('revokePortalLink', $partner)->isVisible());

            $this->recordAction('rotatePortalLink', $partner)->call();
            $rotated = $partner->fresh()->portal_share_uuid;
            $this->assertNotNull($rotated);
            $this->assertNotSame($original, $rotated);

            $this->recordAction('revokePortalLink', $partner->fresh())->call();
            $this->assertNull($partner->fresh()->portal_share_uuid);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function copy_portal_link_action_issues_uuid_and_is_visible_for_active_partners(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $this->actingAs($this->createUserWithRole(TenantRole::Owner));

            $partner = $this->createPartner();
            $this->assertNull($partner->portal_share_uuid);
            $this->assertTrue($this->recordAction('copyPortalLink', $partner)->isVisible());

            $this->recordAction('copyPortalLink', $partner)->call();
            $this->assertNotNull($partner->fresh()->portal_share_uuid);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function portal_link_actions_are_hidden_from_personas_without_the_ability(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $this->actingAs($this->createUserWithRole(TenantRole::ReceivingTechnician));

            $partner = $this->createPartner();
            app(SupplierPortalService::class)->ensurePartnerPortalLink($partner);
            $partner->refresh();

            $this->assertFalse($this->recordAction('rotatePortalLink', $partner)->isVisible());
            $this->assertFalse($this->recordAction('revokePortalLink', $partner)->isVisible());
            $this->assertFalse($this->recordAction('copyPortalLink', $partner)->isVisible());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function bulk_delete_authorizes_each_record_so_referenced_partners_are_skipped(): void
    {
        $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $this->actingAs($this->createUserWithRole(TenantRole::Owner));

            $unreferenced = $this->createPartner();
            $referenced = $this->createPartner();
            $this->createProductFor($referenced);

            $table = TradingPartnersTable::configure(Table::make(new ListTradingPartners));

            $bulkActions = collect($table->getToolbarActions())
                ->flatMap(fn (Action|ActionGroup $action): array => $action instanceof ActionGroup ? $action->getActions() : [$action])
                ->keyBy(fn (Action $action): string => $action->getName());

            $this->assertTrue($bulkActions->has('deactivate'), 'Bulk deactivate must stay available as the safe option.');

            $delete = $bulkActions->get('delete');
            $this->assertInstanceOf(DeleteBulkAction::class, $delete);

            $this->assertTrue($delete->getIndividualRecordAuthorizationResponse($unreferenced)->allowed());
            $this->assertTrue($delete->getIndividualRecordAuthorizationResponse($referenced->fresh())->denied());
        } finally {
            $this->cleanup();
        }
    }

    private function recordAction(string $name, TradingPartner $record): Action
    {
        $table = TradingPartnersTable::configure(Table::make(new ListTradingPartners));

        $recordActions = $table->getRecordActions();
        $this->assertCount(1, $recordActions);
        $this->assertInstanceOf(ActionGroup::class, $recordActions[0]);

        $actions = collect($recordActions[0]->getActions())
            ->each(fn (Action $action) => $action->record($record));

        $action = $actions->first(fn (Action $action): bool => $action->getName() === $name);

        $this->assertNotNull($action, "The trading partners table is missing the [{$name}] action.");

        return $action;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPartner(array $attributes = []): TradingPartner
    {
        $partner = TradingPartner::factory()->create(array_merge([
            'name' => 'Guard Partner '.uniqid(),
            'partner_type' => PartnerType::Wholesaler,
        ], $attributes));

        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function createProductFor(TradingPartner $partner): Product
    {
        $product = Product::factory()->create([
            'trading_partner_id' => $partner->getKey(),
        ]);

        $this->productIds[] = (int) $product->getKey();

        return $product;
    }

    private function createSsccRangeFor(TradingPartner $partner, bool $issued): int
    {
        return (int) DB::table('sscc_number_ranges')->insertGetId([
            'name' => 'Guard Range '.uniqid(),
            'scope' => SsccNumberRangeScope::Partner->value,
            'trading_partner_id' => $partner->getKey(),
            'company_prefix' => '0367891',
            'extension_digit' => '0',
            'index' => 1,
            'increment_by' => 1,
            'range_size' => 10000,
            'start_number' => 1,
            'current_number' => $issued ? 5000 : 1,
            'threshold_percentage' => 80,
            'status' => SsccNumberRangeStatus::Active->value,
            'remaining' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUserWithRole(TenantRole $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        $this->userIds[] = (int) $user->getKey();

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
        if (tenancy()->initialized) {
            if ($this->productIds !== []) {
                Product::query()->whereIn('id', $this->productIds)->delete();
            }

            if ($this->partnerIds !== []) {
                DB::table('sscc_number_ranges')->whereIn('trading_partner_id', $this->partnerIds)->delete();
                DB::table('sites')->whereIn('trading_partner_id', $this->partnerIds)->delete();
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            }

            if ($this->userIds !== []) {
                DB::table('model_has_roles')
                    ->where('model_type', User::class)
                    ->whereIn('model_id', $this->userIds)
                    ->delete();
                User::query()->whereIn('id', $this->userIds)->delete();
            }

            tenancy()->end();
        }

        $this->productIds = [];
        $this->partnerIds = [];
        $this->userIds = [];
    }
}

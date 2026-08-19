<?php

namespace Tests\Feature\Admin;

use App\Actions\Fda\PromoteFdaWdd3plToCatalogSites;
use App\Enums\AdminRole;
use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Pages\ListFdaWdd3plStagings;
use App\Models\Admin;
use App\Support\Auth\AdminRoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PromoteFdaWdd3plActionTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    protected function tearDown(): void
    {
        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }

        parent::tearDown();
    }

    #[Test]
    public function the_promote_action_is_hidden_and_the_handler_is_a_noop(): void
    {
        $this->actAsPlatformAdmin();

        Livewire::test(ListFdaWdd3plStagings::class)
            ->assertSuccessful()
            ->assertActionHidden(TestAction::make('promoteToCatalog'));

        $counts = app(PromoteFdaWdd3plToCatalogSites::class)->handle(false, true);

        $this->assertSame(0, $counts['processed']);
        $this->assertSame(0, $counts['sites_created']);
        $this->assertSame(0, $counts['licenses_upserted']);
    }

    private function actAsPlatformAdmin(): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}

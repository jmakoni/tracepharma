<?php

namespace Tests\Feature\Tables;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\Admins\Pages\ListAdmins;
use App\Filament\Admin\Resources\Admins\Tables\AdminsTable;
use App\Models\Admin;
use App\Support\Auth\AdminRoleSeeder;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Admin\Resources\Announcements\Tables\AnnouncementsTable;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\ListFdaOrganizations;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Tables\FdaOrganizationsTable;
use App\Filament\Admin\Resources\MailTemplates\Pages\ListMailTemplates;
use App\Filament\Admin\Resources\MailTemplates\Tables\MailTemplatesTable;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Tables\TenantsTable;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Zvizvi\FilamentColumnFilters\FilamentColumnFilters;
use Zvizvi\FilamentColumnFilters\Filters\DateColumnFilter;
use Zvizvi\FilamentColumnFilters\Filters\SearchColumnFilter;
use Zvizvi\FilamentColumnFilters\Filters\SelectColumnFilter;

class AdminColumnFiltersTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string, 2: list<string>}>
     */
    public static function selectColumnCases(): array
    {
        return [
            'admins' => [AdminsTable::class, ListAdmins::class, ['name', 'email', 'is_active', 'roles.name']],
            'tenants' => [TenantsTable::class, ListTenants::class, ['name', 'profile', 'status']],
            'announcements' => [AnnouncementsTable::class, ListAnnouncements::class, ['title', 'severity', 'status']],
            'mail_templates' => [MailTemplatesTable::class, ListMailTemplates::class, ['key', 'subject', 'is_active']],
            'fda_organizations' => [FdaOrganizationsTable::class, ListFdaOrganizations::class, ['name']],
        ];
    }

    /**
     * @param  class-string  $tableClass
     * @param  class-string  $pageClass
     * @param  list<string>  $columnNames
     */
    #[Test]
    #[DataProvider('selectColumnCases')]
    public function admin_list_column_filters_are_select_not_search(
        string $tableClass,
        string $pageClass,
        array $columnNames,
    ): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $table = $tableClass::configure(Table::make(new $pageClass));

        foreach ($columnNames as $columnName) {
            $column = $table->getColumn($columnName);
            $this->assertNotNull($column, $columnName);
            $filter = FilamentColumnFilters::getColumnFilter($column);
            $this->assertInstanceOf(SelectColumnFilter::class, $filter, $columnName);
            $this->assertNotInstanceOf(SearchColumnFilter::class, $filter, $columnName);
        }
    }

    #[Test]
    public function admin_date_columns_use_date_filters(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admins = AdminsTable::configure(Table::make(new ListAdmins));
        $tenants = TenantsTable::configure(Table::make(new ListTenants));
        $announcements = AnnouncementsTable::configure(Table::make(new ListAnnouncements));

        $this->assertInstanceOf(
            DateColumnFilter::class,
            FilamentColumnFilters::getColumnFilter($admins->getColumn('created_at')),
        );
        $this->assertInstanceOf(
            DateColumnFilter::class,
            FilamentColumnFilters::getColumnFilter($tenants->getColumn('created_at')),
        );
        $this->assertInstanceOf(
            DateColumnFilter::class,
            FilamentColumnFilters::getColumnFilter($announcements->getColumn('published_at')),
        );
    }

    #[Test]
    public function admin_list_headers_render_funnel_triggers(): void
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);

        try {
            $this->actingAs($admin, 'admin');
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            Livewire::test(ListAdmins::class)
                ->assertSuccessful()
                ->assertSee('fcf-th', false);

            Livewire::test(ListTenants::class)
                ->assertSuccessful()
                ->assertSee('fcf-th', false);
        } finally {
            $admin->delete();
        }
    }
}

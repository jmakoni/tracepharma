<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Resources\MailTemplates\MailTemplateResource;
use App\Filament\Admin\Resources\MailTemplates\Pages\EditMailTemplate;
use App\Filament\Admin\Resources\MailTemplates\Pages\ListMailTemplates;
use App\Models\Admin;
use App\Models\MailTemplate;
use App\Notifications\MailTemplateTestSend;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\Mail\ComposeDatabaseMail;
use App\Support\Mail\MailTemplateCatalog;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MailTemplateResourceTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMailTemplatesTable();
        MailTemplate::syncFromCatalog();
    }

    protected function tearDown(): void
    {
        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function support_cannot_open_mail_templates(): void
    {
        $support = $this->actAsAdmin(AdminRole::Support);

        $this->assertFalse($support->can(Permissions::AdminsManage));
        $this->assertFalse(MailTemplateResource::canAccess());
        $this->assertFalse(MailTemplateResource::canViewAny());
        $this->assertFalse(MailTemplateResource::canCreate());

        Livewire::test(ListMailTemplates::class)->assertForbidden();
    }

    #[Test]
    public function platform_admin_can_list_and_edit_mail_templates(): void
    {
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        $this->assertTrue($admin->can(Permissions::AdminsManage));
        $this->assertTrue(MailTemplateResource::canAccess());
        $this->assertTrue(MailTemplateResource::canViewAny());
        $this->assertFalse(MailTemplateResource::canCreate());

        $template = MailTemplate::query()
            ->where('key', MailTemplateCatalog::OnboardingAcknowledgment)
            ->firstOrFail();

        Livewire::test(ListMailTemplates::class)
            ->assertSuccessful()
            ->assertSee('Customer onboarding — applicant acknowledgment');

        Livewire::test(EditMailTemplate::class, ['record' => $template->getKey()])
            ->assertSuccessful()
            ->assertSee('{{ first_name }}')
            ->assertSee('{{ company_display_name }}');
    }

    #[Test]
    public function preview_uses_catalog_fixtures(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $template = MailTemplate::query()
            ->where('key', MailTemplateCatalog::OnboardingAcknowledgment)
            ->firstOrFail();

        $preview = app(ComposeDatabaseMail::class)
            ->previewPlainText(MailTemplateCatalog::OnboardingAcknowledgment);

        $this->assertStringContainsString('Example Pharmacy', $preview);
        $this->assertStringContainsString('alex@example-pharmacy.test', $preview);
        $this->assertStringContainsString('Hi Alex,', $preview);

        Livewire::test(EditMailTemplate::class, ['record' => $template->getKey()])
            ->mountAction(TestAction::make('preview'))
            ->assertActionMounted(TestAction::make('preview'))
            ->assertActionDataSet(['preview_body' => $preview]);
    }

    #[Test]
    public function platform_admin_can_send_a_test_to_their_email(): void
    {
        Notification::fake();

        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        $template = MailTemplate::query()
            ->where('key', MailTemplateCatalog::DemoAcknowledgment)
            ->firstOrFail();

        Livewire::test(EditMailTemplate::class, ['record' => $template->getKey()])
            ->callAction(TestAction::make('sendTest'));

        Notification::assertSentOnDemand(
            MailTemplateTestSend::class,
            fn (MailTemplateTestSend $notification, array $channels, object $notifiable): bool => $notification->key === MailTemplateCatalog::DemoAcknowledgment
                && $notifiable->routes['mail'] === $admin->email,
        );
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }

    private function ensureMailTemplatesTable(): void
    {
        if (Schema::hasTable('mail_templates')) {
            return;
        }

        $this->artisan('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_08_16_160000_create_mail_templates_table.php',
        ])->assertSuccessful();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Actions\Fda\ResolveFdaOrganizationMatchReview;
use App\Enums\AdminRole;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Pages\ListFdaOrganizationMatchReviews;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Pages\ViewFdaOrganizationMatchReview;
use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Auth\AdminRoleSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaOrganizationMatchReviewActionsTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function platform_admin_can_link_create_and_reject_pending_reviews(): void
    {
        $org = $this->organization('SSOR REG MATCH ORG', 'SSOR REG Match Org');
        $linkReview = $this->review('SSOR REG Link Co', $org->id);
        $createReview = $this->review('SSOR REG Create Co');
        $rejectReview = $this->review('SSOR REG Reject Co');

        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        $this->assertSame('3', FdaOrganizationMatchReviewResource::getNavigationBadge());

        Livewire::test(ViewFdaOrganizationMatchReview::class, ['record' => $linkReview->getKey()])
            ->callAction('linkOrganization', ['fda_organization_id' => $org->id]);

        $linkReview->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_LINKED, $linkReview->status);
        $this->assertSame($org->id, $linkReview->resolved_fda_organization_id);
        $this->assertSame($admin->id, $linkReview->resolved_by_admin_id);
        $this->assertNotNull($linkReview->resolved_at);

        Livewire::test(ViewFdaOrganizationMatchReview::class, ['record' => $createReview->getKey()])
            ->callAction('createOrganization');

        $createReview->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_CREATED_NEW, $createReview->status);
        $this->assertNotNull($createReview->resolved_fda_organization_id);
        $this->assertSame('SSOR REG Create Co', FdaOrganization::query()->find($createReview->resolved_fda_organization_id)?->name);

        Livewire::test(ViewFdaOrganizationMatchReview::class, ['record' => $rejectReview->getKey()])
            ->callAction('rejectReview');

        $rejectReview->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_REJECTED, $rejectReview->status);
        $this->assertNull(FdaOrganizationMatchReviewResource::getNavigationBadge());
    }

    #[Test]
    public function creating_an_organization_that_already_exists_marks_the_review_linked(): void
    {
        $this->organization('SSOR REG EXISTING', 'SSOR REG Existing');
        $review = $this->review('SSOR REG Existing');
        $admin = $this->actAsAdmin(AdminRole::PlatformAdmin);

        app(ResolveFdaOrganizationMatchReview::class)->createOrganization($review, $admin);

        $review->refresh();
        $this->assertSame(FdaOrganizationMatchReview::STATUS_LINKED, $review->status);
    }

    #[Test]
    public function skip_leaves_the_review_pending(): void
    {
        $first = $this->review('SSOR REG Skip A');
        $second = $this->review('SSOR REG Skip B');
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(ViewFdaOrganizationMatchReview::class, ['record' => $first->getKey()])
            ->callAction('skipReview');

        $this->assertSame(FdaOrganizationMatchReview::STATUS_PENDING, $first->fresh()?->status);
        $this->assertSame(FdaOrganizationMatchReview::STATUS_PENDING, $second->fresh()?->status);
    }

    #[Test]
    public function support_cannot_run_review_mutations(): void
    {
        $review = $this->review('SSOR REG Support Co');
        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(ListFdaOrganizationMatchReviews::class)
            ->assertSuccessful()
            ->assertActionHidden(TestAction::make('linkOrganization')->table($review))
            ->assertActionHidden(TestAction::make('createOrganization')->table($review))
            ->assertActionHidden(TestAction::make('rejectReview')->table($review));

        $this->assertSame(FdaOrganizationMatchReview::STATUS_PENDING, $review->fresh()?->status);
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

    private function organization(string $canonical, string $name): FdaOrganization
    {
        return FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => $canonical,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function review(string $originalName, ?int $proposedId = null): FdaOrganizationMatchReview
    {
        return FdaOrganizationMatchReview::query()->create([
            'source' => 'decrs',
            'original_name' => $originalName,
            'canonical_name' => strtoupper($originalName),
            'proposed_fda_organization_id' => $proposedId,
            'confidence' => 80,
            'status' => FdaOrganizationMatchReview::STATUS_PENDING,
            'payload_json' => ['fei_number' => 'SSORREG9999'],
        ]);
    }

    private function cleanup(): void
    {
        FdaOrganizationMatchReview::query()
            ->where('original_name', 'like', 'SSOR REG%')
            ->delete();

        FdaOrganization::query()
            ->where('canonical_name', 'like', 'SSOR REG%')
            ->orWhere('name', 'like', 'SSOR REG%')
            ->delete();

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }
    }
}

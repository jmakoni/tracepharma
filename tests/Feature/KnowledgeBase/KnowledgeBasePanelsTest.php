<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeBase;

use App\Filament\Admin\Pages\EpcisHubSettings;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\App\Pages\DecommissionWorkstation;
use App\Filament\App\Pages\IntegrationHealth;
use App\Filament\App\Pages\PharmacyOutboundDesk;
use App\Filament\App\Pages\Quarantine;
use App\Filament\App\Pages\ReceivingIssues;
use App\Filament\App\Pages\RepackTransformWorkstation;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Pages\SettingsHub;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\KnowledgeBase\PublicAssetImageRenderer;
use Filament\Facades\Filament;
use Guava\FilamentKnowledgeBase\Models\FlatfileNode;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\Config\ConfigurationInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnowledgeBasePanelsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    #[Test]
    public function tenant_users_can_access_app_and_knowledge_base_panels_when_active(): void
    {
        $tenant = $this->ensureDemo2Tenant();
        tenancy()->initialize($tenant);

        $user = User::factory()->create();

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('knowledge-base')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin-knowledge-base')));

        tenancy()->end();
    }

    #[Test]
    public function admins_can_access_admin_and_admin_knowledge_base_panels(): void
    {
        $admin = Admin::factory()->make();

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin-knowledge-base')));
        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('app')));
        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('knowledge-base')));
    }

    #[Test]
    public function knowledge_base_flatfiles_include_operator_workflow_articles(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('knowledge-base'));

        $ids = FlatfileNode::query()
            ->get()
            ->map(fn (FlatfileNode $doc): string => (string) $doc->getKey())
            ->all();

        $this->assertContains('knowledge-base.intro', $ids);
        $this->assertContains('knowledge-base.workflows.decommission', $ids);
        $this->assertContains('knowledge-base.workflows.outbound-shipping', $ids);
        $this->assertContains('knowledge-base.cbv-biz-steps', $ids);
        $this->assertContains('knowledge-base.compliance.quarantine', $ids);
        $this->assertContains('knowledge-base.integrations.integration-health', $ids);
        $this->assertContains('knowledge-base.exceptions.exceptions', $ids);
        $this->assertContains('knowledge-base.settings.settings-hub', $ids);
    }

    #[Test]
    public function admin_knowledge_base_flatfiles_include_admin_intro(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin-knowledge-base'));

        $ids = FlatfileNode::query()
            ->get()
            ->map(fn (FlatfileNode $doc): string => (string) $doc->getKey())
            ->all();

        $this->assertContains('admin-knowledge-base.intro', $ids);
        $this->assertContains('admin-knowledge-base.demo-and-support', $ids);
        $this->assertContains('admin-knowledge-base.tenants.tenants', $ids);
        $this->assertContains('admin-knowledge-base.registry.fda-organizations', $ids);
        $this->assertContains('admin-knowledge-base.operations.epcis-hub-settings', $ids);
    }

    #[Test]
    public function key_workstations_declare_matching_documentation_ids(): void
    {
        $this->assertSame('workflows.decommission', DecommissionWorkstation::getDocumentation());
        $this->assertSame('workflows.outbound-shipping', ScanOutWorkstation::getDocumentation());
        $this->assertSame('workflows.pharmacy-outbound', PharmacyOutboundDesk::getDocumentation());
        $this->assertSame('workflows.repack-transform', RepackTransformWorkstation::getDocumentation());
        $this->assertSame('workflows.receiving-issues', ReceivingIssues::getDocumentation());
        $this->assertSame('compliance.quarantine', Quarantine::getDocumentation());
        $this->assertSame('integrations.integration-health', IntegrationHealth::getDocumentation());
        $this->assertSame('settings.settings-hub', SettingsHub::getDocumentation());
        $this->assertSame('exceptions.exceptions', ExceptionResource::getDocumentation());
        $this->assertSame('operations.epcis-hub-settings', EpcisHubSettings::getDocumentation());
        $this->assertSame('tenants.tenants', TenantResource::getDocumentation());
    }

    #[Test]
    public function public_asset_image_renderer_maps_media_paths(): void
    {
        $renderer = new PublicAssetImageRenderer;
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturn(false);
        $renderer->setConfiguration($config);

        $image = new Image('media/decommission/01-entry.png', 'Decommission entry');
        $html = (string) $renderer->render($image, $this->createMock(ChildNodeRendererInterface::class));

        $this->assertStringContainsString('docs-media/workflows/decommission/01-entry.png', $html);
        $this->assertStringContainsString('alt="Decommission entry"', $html);
    }

    private function ensureDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => 'pharmacy',
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
            if ($tenant->status !== 'active') {
                $tenant->forceFill(['status' => 'active'])->save();
            }
        }

        return $tenant;
    }
}

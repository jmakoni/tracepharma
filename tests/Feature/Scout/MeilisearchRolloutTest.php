<?php

declare(strict_types=1);

namespace Tests\Feature\Scout;

use App\Jobs\Scout\ProvisionTenantScoutIndexes;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Scout\TenantScoutCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSearchEngine;
use Tests\TestCase;

class MeilisearchRolloutTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'collection']);
    }

    #[Test]
    public function sync_index_settings_skips_for_collection_driver(): void
    {
        $this->artisan('tracepharma:scout-sync-index-settings', [
            '--all-tenants' => true,
        ])
            ->expectsOutputToContain('does not use remote index settings')
            ->assertSuccessful();
    }

    #[Test]
    public function scout_health_skips_for_collection_driver(): void
    {
        $this->artisan('tracepharma:scout-health')
            ->expectsOutputToContain('is not Meilisearch')
            ->assertSuccessful();
    }

    #[Test]
    public function sync_index_settings_records_tenant_prefixed_index_names_with_fake_engine(): void
    {
        $tenant = $this->resolveTenant();
        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine, 'fake-settings');

        config(['scout.driver' => 'fake-settings']);

        $this->artisan('tracepharma:scout-sync-index-settings', [
            '--tenant' => $tenant->getKey(),
        ])->assertSuccessful();

        $expectedPrefix = $tenant->getKey();

        $this->assertArrayHasKey("{$expectedPrefix}_products", $engine->indexSettings);
        $this->assertSame(
            ['tenant_id', 'is_active'],
            $engine->indexSettings["{$expectedPrefix}_products"]['filterableAttributes'],
        );
        $this->assertArrayHasKey("{$expectedPrefix}_epcis_events", $engine->indexSettings);
    }

    #[Test]
    public function scout_health_checks_meilisearch_endpoint(): void
    {
        config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://meilisearch.test']);

        Http::fake([
            'meilisearch.test/health' => Http::response(['status' => 'available'], 200),
        ]);

        $this->swapSearchEngine(new FakeSearchEngine([]), 'meilisearch');

        $this->artisan('tracepharma:scout-health')->assertSuccessful();
    }

    #[Test]
    public function scout_health_probes_first_active_tenant_when_not_specified(): void
    {
        config(['scout.driver' => 'meilisearch', 'scout.meilisearch.host' => 'http://meilisearch.test']);

        Http::fake([
            'meilisearch.test/health' => Http::response(['status' => 'available'], 200),
        ]);

        $tenant = $this->resolveTenant();
        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine, 'meilisearch');

        $this->artisan('tracepharma:scout-health')
            ->expectsOutputToContain("Tenant products index probe OK: {$tenant->getKey()}")
            ->assertSuccessful();
    }

    #[Test]
    public function scout_reindex_tenant_returns_failure_when_import_fails(): void
    {
        config(['scout.driver' => 'collection']);

        $tenant = $this->resolveTenant();

        Artisan::swap(new class
        {
            public function call(string $command, array $parameters = [], $outputBuffer = null): int
            {
                return $command === 'scout:import' ? 1 : 0;
            }

            public function output(): string
            {
                return '';
            }
        });

        $command = $this->app->make(\App\Console\Commands\ScoutReindexTenantCommand::class);
        $command->setLaravel($this->app);

        $status = $command->run(
            new \Symfony\Component\Console\Input\ArrayInput([
                '--tenant' => $tenant->getKey(),
                '--model' => 'products',
            ]),
            new \Symfony\Component\Console\Output\BufferedOutput(),
        );

        $this->assertSame(\App\Console\Commands\ScoutReindexTenantCommand::FAILURE, $status);
    }

    #[Test]
    public function provision_tenant_scout_indexes_job_fails_on_sync_failure(): void
    {
        config(['scout.driver' => 'meilisearch']);

        $tenant = $this->resolveTenant();

        Artisan::swap(new class
        {
            public function call(string $command, array $parameters = [], $outputBuffer = null): int
            {
                return $command === 'tracepharma:scout-sync-index-settings' ? 1 : 0;
            }

            public function output(): string
            {
                return '';
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scout index settings sync failed');

        (new ProvisionTenantScoutIndexes($tenant->getKey()))->handle();
    }

    #[Test]
    public function sync_index_settings_propagates_create_index_errors(): void
    {
        $tenant = $this->resolveTenant();
        $engine = new class([]) extends FakeSearchEngine
        {
            public function createIndex($name, array $options = []): void
            {
                throw new \RuntimeException('Meilisearch connection refused');
            }
        };
        $this->swapSearchEngine($engine, 'fake-settings');

        config(['scout.driver' => 'fake-settings']);

        $this->artisan('tracepharma:scout-sync-index-settings', [
            '--tenant' => $tenant->getKey(),
        ])
            ->expectsOutputToContain('Meilisearch connection refused')
            ->assertFailed();
    }

    #[Test]
    public function sync_index_settings_tolerates_index_already_exists(): void
    {
        $tenant = $this->resolveTenant();
        $engine = new class([]) extends FakeSearchEngine
        {
            public function createIndex($name, array $options = []): void
            {
                throw new \RuntimeException("Index `{$name}` already exists");
            }
        };
        $this->swapSearchEngine($engine, 'fake-settings');

        config(['scout.driver' => 'fake-settings']);

        $this->artisan('tracepharma:scout-sync-index-settings', [
            '--tenant' => $tenant->getKey(),
        ])->assertSuccessful();
    }

    #[Test]
    public function scout_reindex_all_iterates_tenants(): void
    {
        config(['scout.driver' => 'collection']);

        $tenant = $this->resolveTenant();

        $this->artisan('tracepharma:scout-reindex-all', [
            '--model' => 'products',
        ])
            ->expectsOutputToContain("Tenant: {$tenant->getKey()}")
            ->assertSuccessful();
    }

    #[Test]
    public function provision_tenant_scout_indexes_job_is_queueable(): void
    {
        Queue::fake();
        config(['scout.driver' => 'meilisearch']);

        $tenant = $this->resolveTenant();

        ProvisionTenantScoutIndexes::dispatch($tenant->getKey());

        Queue::assertPushed(
            ProvisionTenantScoutIndexes::class,
            fn (ProvisionTenantScoutIndexes $job): bool => $job->tenantId === $tenant->getKey(),
        );
    }

    #[Test]
    public function provision_tenant_scout_indexes_job_skips_for_collection_driver(): void
    {
        config(['scout.driver' => 'collection']);

        $tenant = $this->resolveTenant();
        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine, 'fake-settings');
        config(['scout.driver' => 'collection']);

        (new ProvisionTenantScoutIndexes($tenant->getKey()))->handle();

        $this->assertSame([], $engine->indexSettings);
    }

    #[Test]
    public function provision_tenant_scout_indexes_job_syncs_tenant_indexes(): void
    {
        $tenant = $this->resolveTenant();
        $engine = new FakeSearchEngine([]);
        $this->swapSearchEngine($engine, 'meilisearch');

        config(['scout.driver' => 'meilisearch']);

        (new ProvisionTenantScoutIndexes($tenant->getKey()))->handle();

        $this->assertArrayHasKey($tenant->getKey().'_products', $engine->indexSettings);
    }

    #[Test]
    public function tenant_scout_catalog_resolves_models_and_settings(): void
    {
        $this->assertSame(TenantScoutCatalog::MODELS, TenantScoutCatalog::resolveModels('all'));
        $this->assertSame(['products' => Product::class], TenantScoutCatalog::resolveModels('products'));
        $this->assertSame([], TenantScoutCatalog::resolveModels('unknown'));

        $settings = TenantScoutCatalog::indexSettingsFor(Product::class);
        $this->assertSame(['tenant_id', 'is_active'], $settings['filterableAttributes']);
    }

    private function resolveTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID)
            ?? Tenant::query()->orderBy('id')->first();

        if ($tenant === null) {
            $this->markTestSkipped('No tenant available for Scout rollout test.');
        }

        return $tenant;
    }

    private function swapSearchEngine(FakeSearchEngine $engine, string $driver): void
    {
        $this->app->extend(EngineManager::class, function (EngineManager $manager) use ($engine, $driver): EngineManager {
            $manager->extend($driver, fn (): Engine => $engine);

            return $manager;
        });

        $this->app->forgetInstance(EngineManager::class);
    }
}

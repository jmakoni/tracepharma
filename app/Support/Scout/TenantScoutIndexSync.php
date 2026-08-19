<?php

declare(strict_types=1);

namespace App\Support\Scout;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Exceptions\NotSupportedException;
use Meilisearch\Exceptions\ApiException;
use Throwable;

/**
 * Sync Meilisearch (or other UpdatesIndexSettings engine) index settings per tenant.
 */
final class TenantScoutIndexSync
{
    public function __construct(
        private readonly EngineManager $manager,
    ) {}

    /**
     * @param  array<string, class-string<Model>>|null  $models
     * @return list<string> Synced index names
     */
    public function syncTenant(Tenant $tenant, ?array $models = null, ?Engine $engine = null): array
    {
        $models ??= TenantScoutCatalog::MODELS;
        $engine ??= $this->manager->engine();

        if (! $engine instanceof UpdatesIndexSettings) {
            throw new \RuntimeException('The configured Scout engine does not support updating index settings.');
        }

        $alreadyOnTenant = tenancy()->initialized
            && tenant() instanceof Tenant
            && tenant()->getKey() === $tenant->getKey();

        if (! $alreadyOnTenant) {
            tenancy()->initialize($tenant);
        }

        try {
            $synced = [];

            foreach ($models as $class) {
                $indexName = (new $class)->searchableAs();
                $settings = TenantScoutCatalog::indexSettingsForModel($class, $engine);

                $this->ensureIndexExists($engine, $indexName);
                $engine->updateIndexSettings($indexName, $settings);
                $synced[] = $indexName;
            }

            return $synced;
        } finally {
            if (! $alreadyOnTenant) {
                tenancy()->end();
            }
        }
    }

    private function ensureIndexExists(Engine $engine, string $indexName): void
    {
        try {
            $engine->createIndex($indexName);
        } catch (NotSupportedException) {
            return;
        } catch (Throwable $exception) {
            if ($this->isIndexAlreadyExistsError($exception)) {
                return;
            }

            throw $exception;
        }
    }

    private function isIndexAlreadyExistsError(Throwable $exception): bool
    {
        if ($exception instanceof ApiException && $exception->errorCode === 'index_already_exists') {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'already exists');
    }
}

<?php

namespace Tests\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\Engines\Engine;
use RuntimeException;

/**
 * A Scout engine that the container resolves happily and that either answers with
 * fixed keys or fails every request — what an unreachable or unindexed Meilisearch
 * looks like to the application.
 */
class FakeSearchEngine extends Engine implements UpdatesIndexSettings
{
    private const UNAVAILABLE = 'search index unavailable';

    /**
     * Scout keys handed to update()/delete(), in call order.
     *
     * @var list<int|string>
     */
    public array $indexed = [];

    /** @var list<int|string> */
    public array $removed = [];

    /** @var list<string> */
    public array $createdIndexes = [];

    /** @var array<string, array<string, mixed>> */
    public array $indexSettings = [];

    /**
     * @param  list<int|string>|null  $keys  Null makes every query and every index write throw
     */
    public function __construct(private readonly ?array $keys = null) {}

    public function search(Builder $builder)
    {
        if ($this->keys === null) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        return $this->keys;
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->search($builder);
    }

    public function mapIds($results)
    {
        return new Collection($results);
    }

    public function map(Builder $builder, $results, $model)
    {
        return $model->newCollection();
    }

    public function lazyMap(Builder $builder, $results, $model)
    {
        return LazyCollection::empty();
    }

    public function getTotalCount($results)
    {
        return count($results);
    }

    public function update($models)
    {
        if ($this->keys === null) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        foreach ($models as $model) {
            $this->indexed[] = $model->getScoutKey();
        }
    }

    public function delete($models)
    {
        if ($this->keys === null) {
            throw new RuntimeException(self::UNAVAILABLE);
        }

        foreach ($models as $model) {
            $this->removed[] = $model->getScoutKey();
        }
    }

    public function flush($model) {}

    public function createIndex($name, array $options = [])
    {
        $this->createdIndexes[] = $name;
    }

    public function deleteIndex($name) {}

    public function updateIndexSettings(string $name, array $settings = []): void
    {
        $this->indexSettings[$name] = $settings;
    }

    public function configureSoftDeleteFilter(array $settings = []): array
    {
        $settings['filterableAttributes'][] = '__soft_deleted';

        return $settings;
    }
}

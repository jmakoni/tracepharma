<?php

namespace Tracepharma\FilamentTableViews\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class PresetView
{
    protected ?string $icon = null;

    protected ?string $color = 'primary';

    /**
     * @var Closure(Builder): Builder|null
     */
    protected ?Closure $query = null;

    /**
     * @var array{filters: array, search: string, sort: ?string, grouping: ?string, columns: array}
     */
    protected array $state = [
        'filters' => [],
        'search' => '',
        'sort' => null,
        'grouping' => null,
        'columns' => [],
    ];

    protected bool $favorite = true;

    protected bool $isDefault = false;

    public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function icon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function color(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * @param  Closure(Builder): Builder  $query
     */
    public function query(Closure $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function filters(?array $filters): static
    {
        $this->state['filters'] = $filters;

        return $this;
    }

    public function search(?string $search): static
    {
        $this->state['search'] = $search ?? '';

        return $this;
    }

    public function sort(?string $sort): static
    {
        $this->state['sort'] = $sort;

        return $this;
    }

    public function grouping(?string $grouping): static
    {
        $this->state['grouping'] = $grouping;

        return $this;
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     */
    public function columns(array $columns): static
    {
        $this->state['columns'] = $columns;

        return $this;
    }

    public function favorite(bool $favorite = true): static
    {
        $this->favorite = $favorite;

        return $this;
    }

    public function default(bool $default = true): static
    {
        $this->isDefault = $default;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    /**
     * @return Closure(Builder): Builder|null
     */
    public function getQuery(): ?Closure
    {
        return $this->query;
    }

    /**
     * @return array{filters: array, search: string, sort: ?string, grouping: ?string, columns: array}
     */
    public function defaultState(): array
    {
        return $this->state;
    }

    public function isFavorite(): bool
    {
        return $this->favorite;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }
}

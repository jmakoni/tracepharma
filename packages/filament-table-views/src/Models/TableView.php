<?php

namespace Tracepharma\FilamentTableViews\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class TableView extends Model
{
    protected $table = 'table_views';

    /** @var array<string, bool> */
    private static array $tableExistsCache = [];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'table_key',
        'name',
        'icon',
        'color',
        'is_favorite',
        'is_public',
        'is_global',
        'is_default',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_favorite' => 'boolean',
            'is_public' => 'boolean',
            'is_global' => 'boolean',
            'is_default' => 'boolean',
            'state' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public static function tableExists(): bool
    {
        $connection = (new static)->getConnectionName() ?: (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $cacheKey = $connection.'|'.$database;

        return self::$tableExistsCache[$cacheKey] ??= Schema::hasTable((new static)->getTable());
    }

    public static function forgetTableExistsCache(): void
    {
        self::$tableExistsCache = [];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTable(Builder $query, string $tableKey): Builder
    {
        return $query->where('table_key', $tableKey);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, Authenticatable $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('user_id', $user->getKey())
                ->orWhere('is_public', true)
                ->orWhere('is_global', true);
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFavorites(Builder $query): Builder
    {
        return $query->where('is_favorite', true);
    }

    public function clearConflictingDefaults(): void
    {
        if (! $this->is_default) {
            return;
        }

        $query = static::query()
            ->where('table_key', $this->table_key)
            ->where('is_default', true)
            ->when($this->exists, fn (Builder $q): Builder => $q->whereKeyNot($this->getKey()));

        if ($this->is_global) {
            $query->where('is_global', true);
        } else {
            $query->where('user_id', $this->user_id)
                ->where('is_global', false);
        }

        $query->update(['is_default' => false]);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Scout;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Product;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared Scout model catalog and Meilisearch index-settings lookup for tenant indexes.
 */
final class TenantScoutCatalog
{
    /**
     * @var array<string, class-string<Model>>
     */
    public const MODELS = [
        'products' => Product::class,
        'partners' => TradingPartner::class,
        'documents' => EpcisDocument::class,
        'events' => EpcisEvent::class,
    ];

    /**
     * @return array<string, class-string<Model>>|array{}
     */
    public static function resolveModels(?string $choice): array
    {
        $normalized = strtolower((string) $choice);

        if ($normalized === '' || $normalized === 'all') {
            return self::MODELS;
        }

        if (! array_key_exists($normalized, self::MODELS)) {
            return [];
        }

        return [$normalized => self::MODELS[$normalized]];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, mixed>
     */
    public static function indexSettingsFor(string $modelClass): array
    {
        $drivers = array_unique([
            (string) config('scout.driver', 'collection'),
            'meilisearch',
        ]);

        foreach ($drivers as $driver) {
            $settings = config("scout.{$driver}.index-settings.{$modelClass}", []);

            if (is_array($settings) && $settings !== []) {
                return $settings;
            }
        }

        return [];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  object|null  $engine  Engine implementing UpdatesIndexSettings when soft deletes apply
     * @return array<string, mixed>
     */
    public static function indexSettingsForModel(string $modelClass, ?object $engine = null): array
    {
        $settings = self::indexSettingsFor($modelClass);

        if ($engine === null || $settings === []) {
            return $settings;
        }

        if (! config('scout.soft_delete', false)) {
            return $settings;
        }

        if (! in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            return $settings;
        }

        if (! method_exists($engine, 'configureSoftDeleteFilter')) {
            return $settings;
        }

        return $engine->configureSoftDeleteFilter($settings);
    }

    public static function usesRemoteIndexSettings(): bool
    {
        return ! in_array((string) config('scout.driver', 'collection'), ['collection', 'null'], true);
    }

    public static function usesMeilisearch(): bool
    {
        return (string) config('scout.driver', 'collection') === 'meilisearch';
    }
}

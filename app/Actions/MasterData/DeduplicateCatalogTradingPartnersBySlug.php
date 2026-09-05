<?php

namespace App\Actions\MasterData;

use App\Support\PartnerSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill catalog_trading_partners.slug and merge rows that resolve to the same slug.
 *
 * Used by the slug migration before NOT NULL / unique constraints are applied.
 */
final class DeduplicateCatalogTradingPartnersBySlug
{
    public function handle(): void
    {
        if (! Schema::hasTable('catalog_trading_partners')
            || ! Schema::hasColumn('catalog_trading_partners', 'slug')
        ) {
            return;
        }

        $partners = DB::table('catalog_trading_partners')
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($partners->isEmpty()) {
            return;
        }

        /** @var array<string, list<object{id: int|string, name: string}>> $groups */
        $groups = [];

        foreach ($partners as $partner) {
            $slug = PartnerSlug::from((string) $partner->name);
            $groups[$slug][] = $partner;
        }

        foreach ($groups as $slug => $group) {
            usort($group, function (object $a, object $b): int {
                $lengthCompare = strlen((string) $b->name) <=> strlen((string) $a->name);

                if ($lengthCompare !== 0) {
                    return $lengthCompare;
                }

                return ((int) $a->id) <=> ((int) $b->id);
            });

            $canonical = $group[0];
            $duplicateIds = array_map(
                static fn (object $row): int => (int) $row->id,
                array_slice($group, 1),
            );

            DB::table('catalog_trading_partners')
                ->where('id', $canonical->id)
                ->update([
                    'name' => $canonical->name,
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);

            foreach ($duplicateIds as $duplicateId) {
                $this->repointForeignKeys($duplicateId, (int) $canonical->id);
                DB::table('catalog_trading_partners')->where('id', $duplicateId)->delete();
            }
        }

        $remaining = DB::table('catalog_trading_partners')
            ->whereNull('slug')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($remaining as $partner) {
            $baseSlug = PartnerSlug::from((string) $partner->name);
            $slug = $this->uniqueSlug($baseSlug, (int) $partner->id);

            DB::table('catalog_trading_partners')
                ->where('id', $partner->id)
                ->update([
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);
        }
    }

    private function repointForeignKeys(int $fromId, int $toId): void
    {
        if ($fromId === $toId) {
            return;
        }

        if (Schema::hasTable('catalog_sites')
            && Schema::hasColumn('catalog_sites', 'catalog_trading_partner_id')
        ) {
            DB::table('catalog_sites')
                ->where('catalog_trading_partner_id', $fromId)
                ->update(['catalog_trading_partner_id' => $toId]);
        }

        if (Schema::hasTable('fda_product_trading_partner')) {
            $rows = DB::table('fda_product_trading_partner')
                ->where('trading_partner_id', $fromId)
                ->get(['fda_product_id']);

            foreach ($rows as $row) {
                $exists = DB::table('fda_product_trading_partner')
                    ->where('fda_product_id', $row->fda_product_id)
                    ->where('trading_partner_id', $toId)
                    ->exists();

                if ($exists) {
                    DB::table('fda_product_trading_partner')
                        ->where('fda_product_id', $row->fda_product_id)
                        ->where('trading_partner_id', $fromId)
                        ->delete();
                } else {
                    DB::table('fda_product_trading_partner')
                        ->where('fda_product_id', $row->fda_product_id)
                        ->where('trading_partner_id', $fromId)
                        ->update(['trading_partner_id' => $toId]);
                }
            }
        }

        if (Schema::hasTable('catalog_products')
            && Schema::hasColumn('catalog_products', 'catalog_trading_partner_id')
        ) {
            DB::table('catalog_products')
                ->where('catalog_trading_partner_id', $fromId)
                ->update(['catalog_trading_partner_id' => $toId]);
        }
    }

    private function uniqueSlug(string $baseSlug, int $partnerId): string
    {
        $slug = $baseSlug;
        $suffix = 2;

        while (
            DB::table('catalog_trading_partners')
                ->where('slug', $slug)
                ->where('id', '!=', $partnerId)
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Site;
use App\Support\Gs1\OrganizationSglnPrefixes;
use App\Support\Gs1\SglnResolution;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `sgln` column to SGLNs we can stand behind.
 *
 * A recorded SGLN survives only when it parses and encodes the row's own GLN —
 * which is how a partner's SGLN, typed in from their EPCIS, is kept. Organization
 * facilities are then derived from the organization GS1 Company Prefix, a sibling
 * facility prefix, or (last resort) that prefix's length so a GLN always yields
 * an SGLN on save. Partner locations and devices are not guessed.
 *
 * @see SglnResolution
 */
trait DerivesSgln
{
    /** @var array<string, bool> */
    private static array $sglnColumnWritable = [];

    public static function bootDerivesSgln(): void
    {
        static::saving(function (self $model): void {
            $model->applyDerivedSgln();
        });
    }

    public function applyDerivedSgln(): void
    {
        if (! $this->sglnColumnIsWritable()) {
            // A generated column rejects every write, including of the value it generated,
            // so the attribute has to leave the payload entirely.
            unset($this->attributes['sgln']);

            return;
        }

        $gln = is_string($this->getAttribute('gln')) ? $this->getAttribute('gln') : null;
        $current = $this->getAttribute('sgln');
        $current = is_string($current) ? $current : null;
        $orgPrefix = TenantSettings::forTenant(tenant())->companyPrefix();

        if ($this instanceof Site && OrganizationSglnPrefixes::isOrganizationFacility($this)) {
            $siblingPrefixes = $orgPrefix !== null
                ? OrganizationSglnPrefixes::forSite($this)
                : [];

            $this->setAttribute('sgln', SglnResolution::resolve(
                $gln,
                $current !== null ? [$current] : [],
                $orgPrefix,
                $siblingPrefixes,
            ) ?? SglnResolution::fromPrefixLength(
                $gln,
                $orgPrefix,
                SglnResolution::extensionOf($current, $gln),
            ));

            return;
        }

        $this->setAttribute('sgln', SglnResolution::resolve(
            $gln,
            $current !== null ? [$current] : [],
            $orgPrefix,
        ));
    }

    /**
     * Tenant databases that still carry the legacy generated `sgln` column reject any
     * write to it, so leave the attribute alone until the migration has replaced it.
     */
    private function sglnColumnIsWritable(): bool
    {
        $connection = DB::connection($this->getConnectionName());
        $key = $connection->getName().'|'.$connection->getDatabaseName().'|'.$this->getTable();

        if (array_key_exists($key, self::$sglnColumnWritable)) {
            return self::$sglnColumnWritable[$key];
        }

        $column = $connection->selectOne(
            'SELECT EXTRA FROM information_schema.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$this->getTable(), 'sgln'],
        );

        $extra = $column !== null ? strtoupper((string) (((array) $column)['EXTRA'] ?? '')) : '';

        return self::$sglnColumnWritable[$key] = $column !== null && ! str_contains($extra, 'GENERATED');
    }
}

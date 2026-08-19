<?php

namespace App\Console\Commands;

use App\Models\Fda\FdaProductPackaging;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Leave one active `fda_product_packaging` row per canonical NDC-11.
 *
 * Alternate package-NDC spellings of the same NDC-11 (4-4-2 / 5-3-2 / 5-4-2)
 * count as duplicates. Twins are deactivated rather than deleted.
 */
class DedupeFdaPackageNdcCommand extends Command
{
    protected $signature = 'fda:dedupe-package-ndc
        {--dry-run : Report what would change without writing}
        {--chunk=1000 : Rows scanned per chunk}';

    protected $description = 'Deactivate duplicate FDA packaging rows that describe the same package NDC-11';

    /**
     * @var array<string, array{id: int, preferred: bool, ndc11: string|null}>
     */
    private array $keepers = [];

    /**
     * @var array<int, true>
     */
    private array $released = [];

    /**
     * @var array<string, true>
     */
    private array $duplicateGroups = [];

    private bool $dryRun = false;

    private int $scanned = 0;

    private int $deactivated = 0;

    private int $cleared = 0;

    private int $assigned = 0;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));

        $this->connection()
            ->table('fda_product_packaging')
            ->select(['id', 'gtin', 'package_ndc', 'ndc11'])
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById($chunk, function ($rows): void {
                $this->connection()->transaction(function () use ($rows): void {
                    foreach ($rows as $row) {
                        $this->processRow($row);
                    }
                });
            });

        $groups = count($this->duplicateGroups);

        $this->info(($this->dryRun ? '[dry run] ' : '')
            ."Scanned {$this->scanned} active rows, duplicate packages {$groups}, "
            ."deactivated {$this->deactivated}, NDC-11 cleared {$this->cleared}, reassigned {$this->assigned}.");

        return self::SUCCESS;
    }

    private function processRow(object $row): void
    {
        $this->scanned++;

        $canonical = Ndc::toNdc11(is_string($row->package_ndc) ? $row->package_ndc : null);

        if ($canonical === null) {
            return;
        }

        $id = (int) $row->id;
        $ndc11 = is_string($row->ndc11) ? $row->ndc11 : null;
        $preferred = self::publishesNdcEncodedGtin($row);
        $keeper = $this->keepers[$canonical] ?? null;

        if ($keeper === null) {
            $this->keepers[$canonical] = ['id' => $id, 'preferred' => $preferred, 'ndc11' => $ndc11];

            return;
        }

        if ($keeper['id'] === $id) {
            return;
        }

        $this->duplicateGroups[$canonical] = true;

        if ($preferred && ! $keeper['preferred']) {
            $this->deactivate($keeper['id'], $keeper['ndc11'], $canonical);
            $this->keepers[$canonical] = ['id' => $id, 'preferred' => true, 'ndc11' => $ndc11];
        } else {
            $this->deactivate($id, $ndc11, $canonical);
        }

        $this->giveCanonicalToKeeper($canonical);
    }

    private function deactivate(int $id, ?string $ndc11, string $canonical): void
    {
        if ($ndc11 === $canonical) {
            $this->cleared++;
            $this->released[$id] = true;
            $this->write($id, ['ndc11' => null]);
        }

        $this->deactivated++;
        $this->write($id, ['is_active' => false]);
    }

    private function giveCanonicalToKeeper(string $canonical): void
    {
        $keeper = $this->keepers[$canonical];

        if ($keeper['ndc11'] === $canonical || ! $this->canonicalIsFree($canonical, $keeper['id'])) {
            return;
        }

        $this->assigned++;
        $this->write($keeper['id'], ['ndc11' => $canonical]);
        $this->keepers[$canonical]['ndc11'] = $canonical;
    }

    private function canonicalIsFree(string $canonical, int $keeperId): bool
    {
        $holderIds = $this->connection()
            ->table('fda_product_packaging')
            ->where('ndc11', $canonical)
            ->where('id', '!=', $keeperId)
            ->pluck('id');

        foreach ($holderIds as $holderId) {
            if (! isset($this->released[(int) $holderId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(int $id, array $values): void
    {
        if ($this->dryRun) {
            foreach ($values as $column => $value) {
                $this->line(sprintf(
                    'fda_product_packaging %d: %s => %s',
                    $id,
                    $column,
                    $value === null ? 'null' : var_export($value, true),
                ));
            }

            return;
        }

        $this->connection()
            ->table('fda_product_packaging')
            ->where('id', $id)
            ->update($values);
    }

    private function connection(): Connection
    {
        return DB::connection((new FdaProductPackaging)->getConnectionName());
    }

    private static function publishesNdcEncodedGtin(object $row): bool
    {
        if (! is_string($row->gtin) || ! is_string($row->package_ndc)) {
            return false;
        }

        return $row->gtin === Gtin::fromPackageNdc($row->package_ndc);
    }
}

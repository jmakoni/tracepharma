<?php

namespace App\Console\Commands;

use App\Models\Fda\FdaProductPackaging;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Ndc;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Recompute `fda_product_packaging.ndc11` from each row's package NDC.
 *
 * `ndc11` is UNIQUE, so only one row may hold a given NDC-11. The winner is
 * the row whose GTIN is the NDC-encoded GTIN for its own package NDC, else
 * the lowest id. Other holders are released first.
 */
class BackfillFdaProductNdc11Command extends Command
{
    protected $signature = 'fda:backfill-ndc11
        {--dry-run : Report what would change without writing}
        {--chunk=1000 : Rows scanned per chunk}';

    protected $description = 'Recompute FDA packaging NDC-11 values and clear conflicting duplicates';

    /**
     * @var array<string, array{id: int, preferred: bool}>
     */
    private array $owners = [];

    /**
     * @var array<int, string|null>
     */
    private array $ledger = [];

    private bool $dryRun = false;

    private int $scanned = 0;

    private int $assigned = 0;

    private int $cleared = 0;

    private int $conflicts = 0;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $chunk = max(100, (int) $this->option('chunk'));

        $this->connection()
            ->table('fda_product_packaging')
            ->select(['id', 'gtin', 'package_ndc', 'ndc11'])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows): void {
                $this->connection()->transaction(function () use ($rows): void {
                    foreach ($rows as $row) {
                        $this->processRow($row);
                    }
                });
            });

        $this->info(($this->dryRun ? '[dry run] ' : '')
            ."Scanned {$this->scanned}, set {$this->assigned}, cleared {$this->cleared}, duplicate packages {$this->conflicts}.");

        return self::SUCCESS;
    }

    private function processRow(object $row): void
    {
        $this->scanned++;

        $id = (int) $row->id;
        $current = is_string($row->ndc11) ? $row->ndc11 : null;
        $derived = Ndc::toNdc11(is_string($row->package_ndc) ? $row->package_ndc : null);

        if ($derived === null) {
            $this->clear($id, $current);

            return;
        }

        $preferred = self::publishesNdcEncodedGtin($row);
        $owner = $this->owners[$derived] ?? null;

        if ($owner === null) {
            $this->claim($derived, $id, $current, $preferred);

            return;
        }

        if ($owner['id'] === $id) {
            return;
        }

        $this->conflicts++;

        if (! $preferred || $owner['preferred']) {
            $this->clear($id, $current);

            return;
        }

        $this->claim($derived, $id, $current, true, $owner['id']);
    }

    private function claim(
        string $ndc11,
        int $id,
        ?string $current,
        bool $preferred,
        ?int $previousOwnerId = null,
    ): void {
        if ($previousOwnerId !== null && $previousOwnerId !== $id) {
            $this->clear($previousOwnerId, null);
        }

        foreach ($this->otherHolderIds($ndc11, $id) as $holderId) {
            $this->clear($holderId, $ndc11);
        }

        $this->owners[$ndc11] = ['id' => $id, 'preferred' => $preferred];

        $this->set($id, $ndc11, $current);
    }

    /**
     * @return list<int>
     */
    private function otherHolderIds(string $ndc11, int $keepId): array
    {
        return $this->connection()
            ->table('fda_product_packaging')
            ->where('ndc11', $ndc11)
            ->where('id', '!=', $keepId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function set(int $id, string $ndc11, ?string $current): void
    {
        if ($this->currentValue($id, $current) !== $ndc11) {
            $this->assigned++;
            $this->write($id, $ndc11);
        }

        $this->ledger[$id] = $ndc11;
    }

    private function clear(int $id, ?string $current): void
    {
        if ($this->currentValue($id, $current) === null) {
            return;
        }

        $this->cleared++;
        $this->write($id, null);
        $this->ledger[$id] = null;
    }

    private function currentValue(int $id, ?string $fallback): ?string
    {
        return array_key_exists($id, $this->ledger) ? $this->ledger[$id] : $fallback;
    }

    private function write(int $id, ?string $ndc11): void
    {
        if ($this->dryRun) {
            $this->line(sprintf('fda_product_packaging %d: ndc11 => %s', $id, $ndc11 ?? 'null'));

            return;
        }

        $this->connection()
            ->table('fda_product_packaging')
            ->where('id', $id)
            ->update(['ndc11' => $ndc11]);
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

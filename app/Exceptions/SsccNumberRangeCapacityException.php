<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\SsccNumberRange;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Capacity/exhaustion failure after a number-range self-heal (pointer advance / depleted).
 * Heal attributes must survive the outer allocation transaction rollback.
 */
final class SsccNumberRangeCapacityException extends InvalidArgumentException
{
    /**
     * @param  array<string, mixed>  $healAttributes
     */
    public function __construct(
        string $message,
        public readonly int $rangeId,
        public readonly array $healAttributes,
    ) {
        parent::__construct($message);
    }

    /**
     * Persist heal on a fresh PDO so nested/outer transaction rollbacks cannot undo it.
     */
    public function persistHeal(): void
    {
        $model = new SsccNumberRange;
        $source = $model->getConnectionName() ?: (string) config('database.default');
        $healConnection = 'sscc_number_range_heal';
        $base = config("database.connections.{$source}");

        if (! is_array($base)) {
            report(new \RuntimeException("SSCC number range heal: source connection \"{$source}\" is not configured."));

            return;
        }

        config(["database.connections.{$healConnection}" => $base]);
        DB::purge($healConnection);

        try {
            // Eloquent save (not a raw query builder update) so LogsActivity model events fire.
            // Load the existing row first so syncOriginal() reflects true prior values —
            // otherwise the activity log diff treats every healed attribute as changed from null.
            $range = SsccNumberRange::on($healConnection)->find($this->rangeId);

            if ($range === null) {
                $range = new SsccNumberRange;
                $range->setConnection($healConnection);
                $range->exists = true;
                $range->setAttribute($range->getKeyName(), $this->rangeId);
            }

            $range->forceFill($this->healAttributes);
            $range->save();
        } catch (\Throwable $e) {
            // Never rethrow: the caller must surface the original CapacityException for
            // failover, not a heal-persistence failure.
            report($e);
        } finally {
            DB::purge($healConnection);
        }
    }
}

<?php

namespace App\Actions\Epcis;

use App\Models\Epcis\Epc;
use App\Support\Gs1\ElementString;
use Carbon\Carbon;

/**
 * Resolve a warehouse/handheld scan string to a persisted Epc when possible.
 */
final class ResolveEpcFromScan
{
    /**
     * @return array{
     *     epc: ?Epc,
     *     identity: array<string, mixed>,
     *     ilmd_soft_mismatch: ?array<string, mixed>
     * }
     */
    public function handle(string $scan): array
    {
        // AI parsing reads the raw scan so FNC1 still terminates variable-length AIs;
        // already-normalized callers are unaffected because normalization is idempotent.
        $normalized = ElementString::normalize($scan);

        $epc = null;
        $identity = [];

        if (str_starts_with($normalized, 'urn:epc:id:')) {
            $epc = Epc::query()->where('epc_uri', $normalized)->first();
            $keys = app(MaterializeEpcKeys::class)->handle($normalized);
            $identity = $keys ?? ['epc_uri' => $normalized];

            if ($epc === null && $keys !== null) {
                $epc = $this->lookupFromMaterializedKeys($keys);
            }
        } elseif ($sscc = ElementString::ssccIdentity($scan)) {
            $identity = $sscc;
            $epc = Epc::query()->where('sscc18', $sscc['sscc18'])->first();

            if ($epc === null) {
                $epc = Epc::query()->where('ai_00', $sscc['ai_00'])->first();
            }
        } elseif ($sgtin = ElementString::sgtinIdentity($scan)) {
            $identity = $sgtin;
            $epc = Epc::query()->where('ai_01_21', $sgtin['ai_01_21'])->first();

            if ($epc === null) {
                $epc = Epc::query()
                    ->where('gtin14', $sgtin['gtin14'])
                    ->where('serial_number', $sgtin['serial'])
                    ->first();
            }
        }

        $mismatch = null;
        if ($epc !== null) {
            $mismatch = $this->softCheckIlmd($epc, $identity);
        }

        return [
            'epc' => $epc,
            'identity' => $identity,
            'ilmd_soft_mismatch' => $mismatch,
        ];
    }

    /**
     * @param  array<string, mixed>  $keys
     */
    private function lookupFromMaterializedKeys(array $keys): ?Epc
    {
        if (($keys['epc_type'] ?? null) === 'sgtin' && filled($keys['ai_01_21'] ?? null)) {
            return Epc::query()->where('ai_01_21', $keys['ai_01_21'])->first();
        }

        if (($keys['epc_type'] ?? null) === 'sscc') {
            if (filled($keys['sscc18'] ?? null)) {
                $epc = Epc::query()->where('sscc18', $keys['sscc18'])->first();
                if ($epc !== null) {
                    return $epc;
                }
            }

            if (filled($keys['ai_00'] ?? null)) {
                return Epc::query()->where('ai_00', $keys['ai_00'])->first();
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|null
     */
    private function softCheckIlmd(Epc $epc, array $identity): ?array
    {
        $hasLot = filled($identity['lot_number'] ?? null);
        $hasExpiry = filled($identity['expiry_yymmdd'] ?? null);

        if (! $hasLot && ! $hasExpiry) {
            return null;
        }

        $ilmd = $epc->ilmd;
        if ($ilmd === null) {
            return null;
        }

        $mismatch = [];

        if ($hasLot && filled($ilmd->lot_number) && (string) $ilmd->lot_number !== (string) $identity['lot_number']) {
            $mismatch['lot_number'] = [
                'scan' => (string) $identity['lot_number'],
                'ilmd' => (string) $ilmd->lot_number,
            ];
        }

        if ($hasExpiry && $ilmd->expiry_date !== null) {
            $scanExpiry = $this->parseYymmdd((string) $identity['expiry_yymmdd']);
            if ($scanExpiry !== null) {
                $ilmdExpiry = $ilmd->expiry_date->toDateString();
                if ($scanExpiry !== $ilmdExpiry) {
                    $mismatch['expiry_date'] = [
                        'scan' => $scanExpiry,
                        'ilmd' => $ilmdExpiry,
                        'scan_yymmdd' => (string) $identity['expiry_yymmdd'],
                    ];
                }
            }
        }

        return $mismatch === [] ? null : $mismatch;
    }

    private function parseYymmdd(string $yymmdd): ?string
    {
        if (! preg_match('/^\d{6}$/', $yymmdd)) {
            return null;
        }

        $year = 2000 + (int) substr($yymmdd, 0, 2);
        $month = (int) substr($yymmdd, 2, 2);
        $day = (int) substr($yymmdd, 4, 2);

        if ($month < 1 || $month > 12) {
            return null;
        }

        try {
            if ($day === 0) {
                return Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            }

            return Carbon::create($year, $month, $day)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}

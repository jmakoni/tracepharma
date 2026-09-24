<?php

declare(strict_types=1);

namespace App\Actions\Vrs;

use App\Models\Epcis\Epc;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Verification;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use DomainException;

/**
 * Block receive Complete until confirmed SGTINs have a VRS `verified` row.
 */
final class AssertReceivingVrsComplete
{
    public function applies(): bool
    {
        if (! TenantSettings::forTenant(tenant())->hardGateReceiveComplete()) {
            return false;
        }

        if (! TenantFeatures::forTenant(tenant())->supportsVrs()) {
            return false;
        }

        $driver = config('vrs.driver');

        return $driver !== null && $driver !== '' && $driver !== 'null';
    }

    public function handle(ReceivingSession $session): void
    {
        if (! $this->applies()) {
            return;
        }

        $pending = 0;
        $failed = 0;

        $lines = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->whereNotNull('epc_id')
            ->with('epc')
            ->get();

        foreach ($lines as $line) {
            $epc = $line->epc;
            if (! $epc instanceof Epc || $epc->epc_type === 'sscc') {
                continue;
            }

            $gtin14 = trim((string) $epc->gtin14);
            $serial = trim((string) $epc->serial_number);
            if ($gtin14 === '' || $serial === '') {
                continue;
            }

            $since = $line->confirmed_at ?? $session->opened_at;
            $latest = Verification::query()
                ->where('gtin14', $gtin14)
                ->where('serial', $serial)
                ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since))
                ->orderByDesc('id')
                ->first();

            if ($latest === null) {
                $pending++;

                continue;
            }

            if ($latest->status === 'verified') {
                continue;
            }

            if (in_array($latest->status, ['failed', 'suspect'], true)) {
                $failed++;

                continue;
            }

            $pending++;
        }

        if ($failed > 0) {
            throw new DomainException(
                'VRS failed or suspect for '.$failed.' unit(s) — quarantine and investigate before completing receive.',
            );
        }

        if ($pending > 0) {
            throw new DomainException(
                'VRS pending for '.$pending.' unit(s) — wait or retry verification before completing receive.',
            );
        }
    }
}

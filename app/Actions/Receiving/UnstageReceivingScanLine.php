<?php

namespace App\Actions\Receiving;

use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use DomainException;

/**
 * Remove a staged scan line and release the serial reservation.
 */
final class UnstageReceivingScanLine
{
    public function handle(ReceivingScanLine $line): ReceivingSession
    {
        if ($line->status !== 'staged') {
            throw new DomainException('Only staged scan lines can be removed from the staging queue.');
        }

        $session = $line->session;
        if ($session === null) {
            throw new DomainException('Receiving session not found for scan line.');
        }

        if (! in_array($session->status, ['open', 'in_progress'], true)) {
            throw new DomainException('Cannot unstage scans on a closed receiving session.');
        }

        if ($line->line_role === 'expected') {
            $line->forceFill([
                'status' => 'expected',
                'scan_raw' => null,
            ])->save();
        } else {
            $line->delete();
        }

        return $session->fresh() ?? $session;
    }
}

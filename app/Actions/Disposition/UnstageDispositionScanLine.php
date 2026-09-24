<?php

namespace App\Actions\Disposition;

use App\Models\Disposition\DispositionScanLine;
use App\Models\Disposition\DispositionSession;
use DomainException;

/**
 * Remove a staged disposition scan line and release the serial reservation.
 */
final class UnstageDispositionScanLine
{
    public function handle(DispositionScanLine $line): DispositionSession
    {
        if ($line->status !== 'staged') {
            throw new DomainException('Only staged scan lines can be removed from the disposition queue.');
        }

        $session = $line->session;
        if ($session === null) {
            throw new DomainException('Disposition session not found for scan line.');
        }

        if ($session->status !== 'open') {
            throw new DomainException('Cannot unstage scans on a closed disposition session.');
        }

        $line->delete();

        $stagedCount = $session->scanLines()->where('status', 'staged')->count();
        $session->forceFill(['staged_count' => $stagedCount])->save();

        return $session->fresh() ?? $session;
    }
}

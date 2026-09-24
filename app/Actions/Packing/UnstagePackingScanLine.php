<?php

namespace App\Actions\Packing;

use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use DomainException;

/**
 * Remove a staged pack scan line and release the serial reservation.
 */
final class UnstagePackingScanLine
{
    public function handle(PackingScanLine $line): PackingSession
    {
        if ($line->status !== 'staged') {
            throw new DomainException('Only staged scan lines can be removed from the pack queue.');
        }

        $session = $line->session;
        if ($session === null) {
            throw new DomainException('Pack session not found for scan line.');
        }

        if ($session->status !== 'open') {
            throw new DomainException('Cannot unstage scans on a closed pack session.');
        }

        $line->delete();

        $stagedCount = $session->scanLines()->where('status', 'staged')->count();
        $session->forceFill(['staged_count' => $stagedCount])->save();

        return $session->fresh() ?? $session;
    }
}

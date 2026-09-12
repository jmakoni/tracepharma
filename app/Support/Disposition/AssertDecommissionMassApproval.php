<?php

namespace App\Support\Disposition;

use App\Enums\EpcisAuthoredKind;
use App\Models\User;
use App\Support\Auth\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Mass decommission (N > threshold) requires a second distinct user with mass_approve.
 * Threshold applies to cumulative decommissions at a site within the configured window.
 */
final class AssertDecommissionMassApproval
{
    public function handle(int $epcCount, ?int $approverUserId, ?int $actorUserId = null, ?int $siteId = null): void
    {
        $threshold = $this->threshold();
        $effectiveCount = $this->effectiveCount($epcCount, $siteId);

        if (! $this->requiresSecondApprover($epcCount, $siteId)) {
            return;
        }

        if ($approverUserId === null || $approverUserId <= 0) {
            throw new InvalidArgumentException(
                "Mass decommission of {$effectiveCount} EPC(s) exceeds the threshold of {$threshold} and requires a second approver.",
            );
        }

        $actorUserId ??= auth()->id() !== null ? (int) auth()->id() : null;

        if ($actorUserId !== null && $approverUserId === $actorUserId) {
            throw new InvalidArgumentException(
                'Mass decommission cannot be self-approved — a different user with mass-approve authority is required.',
            );
        }

        $approver = User::query()->find($approverUserId);
        if (! $approver instanceof User) {
            throw new InvalidArgumentException('Mass decommission approver was not found.');
        }

        if (! $approver->can(Permissions::DecommissionMassApprove)) {
            throw new InvalidArgumentException(
                'Mass decommission approver is not authorized (requires decommission mass-approve permission).',
            );
        }
    }

    public function requiresSecondApprover(int $epcCount, ?int $siteId = null): bool
    {
        return $this->effectiveCount($epcCount, $siteId) > $this->threshold();
    }

    public function effectiveCount(int $epcCount, ?int $siteId = null): int
    {
        return max(0, $epcCount) + $this->recentDecommissionedEpcCount($siteId);
    }

    public function recentDecommissionedEpcCount(?int $siteId): int
    {
        if ($siteId === null || $siteId <= 0) {
            return 0;
        }

        $windowHours = (int) config('tracepharma.decommission.mass_window_hours', 8);
        $since = now()->subHours($windowHours);

        $query = DB::table('event_epcs')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
            ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
            ->where('epcis_documents.authored_kind', EpcisAuthoredKind::Decommissioning->value)
            ->where('epcis_documents.ship_from_site_id', $siteId)
            ->where('epcis_events.event_time', '>=', $since);

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $query->whereNull('epcis_events.superseded_at');
        }

        return (int) $query->distinct()->count('event_epcs.epc_id');
    }

    public function threshold(): int
    {
        return (int) config('tracepharma.decommission.mass_threshold', 10);
    }
}

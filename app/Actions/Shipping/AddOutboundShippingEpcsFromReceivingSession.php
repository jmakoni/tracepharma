<?php

namespace App\Actions\Shipping;

use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Shipping\ShippableEpcsAtSite;
use DomainException;

/**
 * Copy confirmed EPCs from a completed receiving session onto a ship order.
 */
final class AddOutboundShippingEpcsFromReceivingSession
{
    public function __construct(
        private readonly ConfirmOutboundShippingScan $confirmOutboundShippingScan,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
    ) {}

    /**
     * @param  list<int>|null  $epcIds
     * @return array{
     *     added: int,
     *     skipped: int,
     *     errors: list<string>
     * }
     */
    public function handle(
        OutboundShippingSession $session,
        int $receivingSessionId,
        ?array $epcIds = null,
        ?int $userId = null,
    ): array {
        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        if (! $session->canScan()) {
            throw new DomainException('Cannot add EPCs to a closed ship order.');
        }

        $receiving = ReceivingSession::query()->find($receivingSessionId);

        if ($receiving === null) {
            throw new DomainException('Receiving session was not found.');
        }

        if ($session->site_id === null) {
            throw new DomainException('Ship order has no site — cannot add EPCs from receiving.');
        }

        if ($receiving->site_id === null) {
            throw new DomainException('Receiving session has no site — cannot copy EPCs to a ship order.');
        }

        if ($receiving->status !== 'completed') {
            throw new DomainException('Receiving session must be completed before adding units to a ship order.');
        }

        if ($receiving->receiving_events_generated_at === null) {
            throw new DomainException('Receiving EPCIS events must be generated before adding units to a ship order.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            if ($session->site_id !== null) {
                SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
            }
            if ($receiving->site_id !== null) {
                SiteAccess::assertCanAccessSite($user, (int) $receiving->site_id);
            }
        }

        // Outermost confirmed lines only: 'parent' SSCC pallets, plus 'child' lines that
        // are not nested under any confirmed parent — bare SGTIN units scanned directly
        // on a scan-first session. A child with parent_epc_id set is already represented
        // by its confirmed parent pallet, so including it too would double-add the unit.
        $lines = ReceivingScanLine::query()
            ->where('receiving_session_id', $receiving->getKey())
            ->where('status', 'confirmed')
            ->where(function ($query): void {
                $query->where('line_role', 'parent')
                    ->orWhere(function ($child): void {
                        $child->where('line_role', 'child')->whereNull('parent_epc_id');
                    });
            })
            ->when($epcIds !== null, fn ($query) => $query->whereIn('epc_id', $epcIds))
            ->with('epc:id,epc_uri,sscc18,gtin14,serial_number,epc_type')
            ->orderByRaw("CASE WHEN line_role = 'parent' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        // Asked only about the units on these lines: the site's whole on-hand inventory
        // is a far larger set to materialize than the handful being copied.
        $shippableIds = $this->shippableEpcsAtSite->filter(
            (int) $session->site_id,
            $lines->pluck('epc_id')->all(),
        );
        $added = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $line) {
            $epc = $line->epc;
            if ($epc === null) {
                $skipped++;

                continue;
            }

            if (! in_array((int) $epc->getKey(), $shippableIds, true)) {
                $skipped++;

                continue;
            }

            $scan = (string) ($line->scan_raw ?: $epc->epc_uri);
            $result = $this->confirmOutboundShippingScan->handle($session->fresh(), $scan, $userId);

            if ($result['effect'] === 'confirmed') {
                $added++;
                $session = $session->fresh();
            } elseif ($result['effect'] === 'already_confirmed') {
                $skipped++;
            } else {
                $errors[] = $result['message'];
                $skipped++;
            }
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}

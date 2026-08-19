<?php

namespace App\Actions\Transferring;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\Epc;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\AssertOutermostSsccHasChildren;
use App\Support\Shipping\EpcOnOpenShippingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\Transferring\EpcOnAnotherOpenTransferringSession;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Confirm an ad-hoc SSCC/SGTIN scan onto an open transferring session.
 *
 * A transfer moves stock off one of our docks, so the unit must be on hand at the
 * from site — organization-wide custody is not enough, or a unit resting at
 * another site could be transferred out of a dock it was never at. Checks run
 * inside the session lock, as on the ship scan, so a hold or a competing
 * shipment landing mid-scan cannot slip past.
 */
final class ConfirmTransferringScan
{
    public function __construct(
        private readonly ResolveEpcFromScan $resolveEpcFromScan,
        private readonly ReceivingGate $receivingGate,
        private readonly EpcOnAnotherOpenTransferringSession $epcOnAnotherOpenTransferringSession,
        private readonly EpcOnOpenShippingSession $epcOnOpenShippingSession,
        private readonly EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
        private readonly EpcCustodyGate $custodyGate,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly AssertOutermostSsccHasChildren $assertOutermostSsccHasChildren,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     line: ?TransferringScanLine,
     *     epc: ?Epc,
     *     effect: 'confirmed'|'already_confirmed'|'not_found'|'quarantined'|'not_at_from_site'|'not_in_custody'|'session_closed'|'double_transfer'|'on_open_ship'|'on_open_receive'
     * }
     */
    public function handle(
        TransferringSession $session,
        string $scan,
        ?int $userId = null,
    ): array {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            throw new DomainException('Transferring is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $session->from_site_id);
        }

        $scan = ElementString::normalize($scan);
        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];

        if ($epc === null) {
            return [
                'ok' => false,
                'message' => 'Barcode not recognized. Check the label and try again.',
                'line' => null,
                'epc' => null,
                'effect' => 'not_found',
            ];
        }

        return DB::transaction(function () use ($session, $scan, $userId, $epc): array {
            $session = TransferringSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status !== 'open') {
                return [
                    'ok' => false,
                    'message' => 'This transfer session is no longer open.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'session_closed',
                ];
            }

            $existing = TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->where('epc_id', $epc->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [
                    'ok' => true,
                    'message' => 'Already confirmed.',
                    'line' => $existing,
                    'epc' => $epc,
                    'effect' => 'already_confirmed',
                ];
            }

            // Inside the transaction: a hold opened after the barcode resolved must still
            // block the line, and the session row is already locked against a racing scan.
            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            if ($hold !== null) {
                $caseId = $hold->exception_id;
                $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

                return [
                    'ok' => false,
                    'message' => 'Under quarantine'.$suffix.'. Clear or release quarantine before transferring.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'quarantined',
                ];
            }

            if ($this->epcOnAnotherOpenReceivingSession->existsOnAnyExclusiveSession($epc)) {
                return [
                    'ok' => false,
                    'message' => 'Already confirmed on an open receive session.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'on_open_receive',
                ];
            }

            if (! $this->shippableEpcsAtSite->contains((int) $session->from_site_id, (int) $epc->getKey())) {
                return [
                    'ok' => false,
                    'message' => 'This unit is not on hand at the transfer-from site.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'not_at_from_site',
                ];
            }

            // Quarantine was checked above in this transaction; custody alone here avoids
            // a duplicate hold message.
            try {
                $this->custodyGate->assertInCustody($epc, 'transferring');
            } catch (InvalidArgumentException $exception) {
                return [
                    'ok' => false,
                    'message' => $exception->getMessage(),
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'not_in_custody',
                ];
            }

            if ($this->epcOnAnotherOpenTransferringSession->exists($epc, $session)) {
                return [
                    'ok' => false,
                    'message' => 'Already on another open transfer session.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'double_transfer',
                ];
            }

            try {
                $this->assertOutermostSsccHasChildren->handle($epc);
            } catch (InvalidArgumentException $exception) {
                return [
                    'ok' => false,
                    'message' => $exception->getMessage(),
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'not_at_from_site',
                ];
            }

            if ($this->epcOnOpenShippingSession->exists($epc)) {
                return [
                    'ok' => false,
                    'message' => 'Already on another open ship order.',
                    'line' => null,
                    'epc' => $epc,
                    'effect' => 'on_open_ship',
                ];
            }

            $line = TransferringScanLine::query()->create([
                'transferring_session_id' => $session->getKey(),
                'epc_id' => $epc->getKey(),
                'status' => 'confirmed',
                'scan_raw' => $scan,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
            ]);

            $session->forceFill([
                'confirmed_count' => (int) $session->confirmed_count + 1,
            ])->save();

            return [
                'ok' => true,
                'message' => 'Confirmed for transfer.',
                'line' => $line,
                'epc' => $epc,
                'effect' => 'confirmed',
            ];
        });
    }
}

<?php

namespace App\Actions\Quarantine;

use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\ResolveEpcLastKnownGln;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class OpenQuarantineHold
{
    public function __construct(
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
    ) {}

    /**
     * Open a quarantine hold for an EPC and/or document.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function handle(
        string $reason,
        ?Epc $epc = null,
        ?EpcisDocument $document = null,
        string $severity = 'warning',
        ?array $meta = null,
        ExceptionCase|int|null $exception = null,
        ?User $actor = null,
    ): QuarantineHold {
        $exceptionCase = $exception instanceof ExceptionCase
            ? $exception
            : ($exception !== null ? ExceptionCase::query()->find((int) $exception) : null);
        $exceptionId = $exceptionCase?->getKey() !== null ? (int) $exceptionCase->getKey() : null;

        $actor ??= auth()->user();

        $this->assertAuthorized($exceptionId, $actor);
        $this->assertSiteAccess($epc, $document ?? $exceptionCase?->document, $exceptionCase, $actor);

        if ($epc !== null) {
            return DB::transaction(function () use ($reason, $epc, $document, $severity, $meta, $exceptionId): QuarantineHold {
                Epc::query()->whereKey($epc->getKey())->lockForUpdate()->firstOrFail();

                $existing = QuarantineHold::query()
                    ->where('epc_id', $epc->getKey())
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    // Prefer binding an orphan hold to the filing case; if another case already
                    // owns it, leave exception_id alone — caller attaches via exception_epcs pivot.
                    if ($exceptionId !== null && $existing->exception_id === null) {
                        $existing->forceFill(['exception_id' => $exceptionId])->save();
                    }

                    return $existing;
                }

                return QuarantineHold::query()->create([
                    'epc_id' => $epc->getKey(),
                    'document_id' => $document?->getKey(),
                    'exception_id' => $exceptionId,
                    'reason' => $reason,
                    'status' => 'open',
                    'severity' => $severity,
                    'opened_at' => now(),
                    'meta' => $meta,
                ]);
            });
        }

        return QuarantineHold::query()->create([
            'epc_id' => null,
            'document_id' => $document?->getKey(),
            'exception_id' => $exceptionId,
            'reason' => $reason,
            'status' => 'open',
            'severity' => $severity,
            'opened_at' => now(),
            'meta' => $meta,
        ]);
    }

    private function assertAuthorized(?int $exceptionId, ?User $actor): void
    {
        if ($exceptionId !== null) {
            if (! JobRoleAccess::allowsForActor(Permissions::NavExceptions, $actor)
                && ! JobRoleAccess::allowsForActor(Permissions::NavReceive, $actor)
                && ! JobRoleAccess::allowsForActor(Permissions::NavVerify, $actor)) {
                throw new DomainException('Exceptions are not authorized for your job role.');
            }

            return;
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavExceptions, $actor)) {
            throw new DomainException('Exceptions are not authorized for your job role.');
        }
    }

    private function assertSiteAccess(
        ?Epc $epc,
        ?EpcisDocument $document,
        ?ExceptionCase $exceptionCase,
        ?User $actor,
    ): void {
        if (! $actor instanceof User) {
            return;
        }

        if ($actor->can(Permissions::SitesAccessAll)) {
            return;
        }

        $siteId = $exceptionCase?->site_id !== null
            ? (int) $exceptionCase->site_id
            : null;

        if ($siteId === null && $document?->ship_to_site_id !== null) {
            $siteId = (int) $document->ship_to_site_id;
        }

        if ($siteId === null && $epc !== null) {
            $gln = $this->lastKnownGln->forEpc($epc);
            $siteId = SiteAccess::organizationSiteIdForGln($gln);
        }

        if (! SiteAccess::canAccessShipToSite($actor, $siteId)) {
            throw new AuthorizationException('You do not have access to open holds for this site.');
        }
    }
}

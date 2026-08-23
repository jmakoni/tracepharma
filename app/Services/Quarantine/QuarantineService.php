<?php

namespace App\Services\Quarantine;

use App\Actions\Quarantine\OpenQuarantineHold;
use App\Actions\Quarantine\ReleaseQuarantineHold;
use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionDisposition;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\Quarantine\QuarantineHold;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QuarantineService
{
    public function __construct(
        private readonly OpenQuarantineHold $openHold,
        private readonly ReleaseQuarantineHold $releaseHold,
        private readonly ExceptionService $exceptions,
    ) {}

    /**
     * @param  list<int>  $epcIds
     * @param  array<string, mixed>|null  $meta
     */
    public function openForCase(
        ExceptionCase $case,
        array $epcIds,
        string $reason,
        ?User $actor = null,
        ?EpcisDocument $document = null,
        ?array $meta = null,
    ): int {
        $opened = 0;
        $sharedHoldNotes = [];
        $document ??= $case->document;
        $holdMeta = array_filter([
            'source' => 'exception_case',
            ...($meta ?? []),
        ], static fn ($value) => $value !== null && $value !== '');

        DB::transaction(function () use (
            $case,
            $epcIds,
            $reason,
            $document,
            $holdMeta,
            $actor,
            &$opened,
            &$sharedHoldNotes,
        ): void {
            foreach (array_unique(array_filter($epcIds)) as $epcId) {
                $epc = Epc::query()->find((int) $epcId);
                if ($epc === null) {
                    continue;
                }

                $hold = $this->openHold->handle(
                    reason: $reason,
                    epc: $epc,
                    document: $document,
                    severity: $case->severity?->value === ExceptionSeverity::Critical->value ? 'error' : 'warning',
                    meta: $holdMeta,
                    exception: $case,
                    actor: $actor,
                );

                if ($hold->wasRecentlyCreated) {
                    $opened++;
                } elseif (
                    $hold->exception_id !== null
                    && (int) $hold->exception_id !== (int) $case->getKey()
                ) {
                    $sharedHoldNotes[] = (int) $epc->getKey();
                }

                $case->epcs()->syncWithoutDetaching([(int) $epc->getKey()]);
            }

            $case->forceFill(['serials_affected' => $this->countOpenHoldsForCase($case)])->save();
        });

        if ($actor !== null) {
            $case->logActivity(
                ExceptionActivityKind::System,
                $actor,
                "Opened quarantine for {$opened} unit(s). Reason: {$reason}",
                ExceptionActivityVisibility::Internal,
                array_filter([
                    'receiving_session_id' => $holdMeta['receiving_session_id'] ?? null,
                ], static fn ($value) => $value !== null),
            );

            if ($sharedHoldNotes !== []) {
                $case->logActivity(
                    ExceptionActivityKind::System,
                    $actor,
                    'Linked to existing open quarantine hold(s) owned by another case for EPC id(s): '
                        .implode(', ', $sharedHoldNotes).'.',
                    ExceptionActivityVisibility::Internal,
                    [
                        'shared_hold_epc_ids' => $sharedHoldNotes,
                        'receiving_session_id' => $holdMeta['receiving_session_id'] ?? null,
                    ],
                );
            }
        }

        return $opened;
    }

    /**
     * Release holds this case is done with. A hold's `exception_id` only names the
     * case that first opened it, but other open cases can share the same EPC via the
     * `exception_epcs` pivot (see {@see openForCase}). Releasing by `exception_id`
     * alone would free a hold out from under a still-open case that also flagged the
     * same unit, so a hold is only actually released once no other open case still
     * references its EPC — and never while another case for that EPC has an
     * Illegitimate disposition (open or terminal). Illegitimate disposition on this
     * case itself rejects release entirely. Otherwise the hold stays open (and this
     * case stays blocked on it via {@see ExceptionCase::hasBlockingOpenQuarantineHolds()})
     * until the other case releases it too.
     */
    public function releaseForCase(ExceptionCase $case, User $actor, string $reason = 'Released from quarantine'): int
    {
        if ($case->disposition === ExceptionDisposition::Illegitimate) {
            throw ValidationException::withMessages([
                'disposition' => 'Cannot release quarantine while disposition is Illegitimate. Holds must remain for physical segregation and FDA follow-up.',
            ]);
        }

        $epcIds = $case->epcs()->pluck('epcs.id')->all();

        $holds = QuarantineHold::query()
            ->open()
            ->where(function ($query) use ($case, $epcIds): void {
                $query->where('exception_id', $case->getKey());

                if ($epcIds !== []) {
                    $query->orWhereIn('epc_id', $epcIds);
                }
            })
            ->get();

        $released = 0;
        $kept = 0;

        foreach ($holds as $hold) {
            $beforeStatus = $hold->status;
            $this->releaseHold->handle($hold, $reason, $actor, $case);

            if ($hold->fresh()->status === 'released' && $beforeStatus === 'open') {
                $released++;
            } else {
                $kept++;
            }
        }

        $case->logActivity(
            ExceptionActivityKind::System,
            $actor,
            "Released {$released} quarantine hold(s). {$reason}".
                ($kept > 0 ? " ({$kept} kept open — still referenced by another open or illegitimate case.)" : ''),
            ExceptionActivityVisibility::Internal,
        );

        $this->refreshSerialsAffected($case->fresh() ?? $case);

        return $released;
    }

    public function ensureShareLink(ExceptionCase $case, ?int $ttlDays = null): ExceptionCase
    {
        $ttlDays = max(1, $ttlDays ?? $this->linkTtlDays());
        $updates = [
            'share_expires_at' => now()->addDays($ttlDays),
        ];

        if ($case->share_uuid === null) {
            $updates['share_uuid'] = (string) Str::uuid();
        }

        $case->forceFill($updates)->save();

        return $case->refresh();
    }

    public function signedSupplierUrl(ExceptionCase $case): string
    {
        $case = $this->ensureShareLink($case);

        return URL::temporarySignedRoute(
            'tenant.supplier-quarantine.show',
            $case->share_expires_at ?? now()->addDays($this->linkTtlDays()),
            ['shareUuid' => $case->share_uuid],
        );
    }

    public function signedSupplierCommentUrl(ExceptionCase $case): string
    {
        $case = $this->ensureShareLink($case);

        return URL::temporarySignedRoute(
            'tenant.supplier-quarantine.comment',
            $case->share_expires_at ?? now()->addDays($this->linkTtlDays()),
            ['shareUuid' => $case->share_uuid],
        );
    }

    public function signedSupplierUploadUrl(ExceptionCase $case): string
    {
        $case = $this->ensureShareLink($case);

        return URL::temporarySignedRoute(
            'tenant.supplier-quarantine.upload',
            $case->share_expires_at ?? now()->addDays($this->linkTtlDays()),
            ['shareUuid' => $case->share_uuid],
        );
    }

    public function linkTtlDays(): int
    {
        return max(1, (int) config(
            'tracepharma.supplier_portal.link_ttl_days',
            30,
        ));
    }

    public function clearForDistribution(ExceptionCase $case, User $actor, string $notes): ExceptionCase
    {
        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Clearance notes are required.',
            ]);
        }

        $this->releaseForCase($case, $actor, 'Cleared for distribution: '.$notes);

        $case->refresh();

        if ($case->hasBlockingOpenQuarantineHolds()) {
            throw ValidationException::withMessages([
                'quarantine' => 'Cannot clear for distribution while open quarantine hold(s) remain on linked unit(s). '
                    .'Release or resolve sibling case(s) referencing the same serial(s) first.',
            ]);
        }

        $case->forceFill([
            'disposition' => ExceptionDisposition::Cleared->value,
        ])->save();

        if ($case->status === ExceptionStatus::WaitingPartner) {
            $this->exceptions->transition($case, ExceptionStatus::Investigating, $actor, 'Supplier investigation complete — cleared.');
            $case->refresh();
        }

        $case->logActivity(
            ExceptionActivityKind::Resolution,
            $actor,
            'Disposition: Cleared for distribution. '.$notes,
            ExceptionActivityVisibility::Internal,
            ['disposition' => ExceptionDisposition::Cleared->value],
        );

        return $case->fresh() ?? $case;
    }

    public function markIllegitimate(ExceptionCase $case, User $actor, string $notes): ExceptionCase
    {
        if (blank($notes)) {
            throw ValidationException::withMessages([
                'notes' => 'Illegitimate disposition notes are required.',
            ]);
        }

        $epcIds = $case->epcs()->pluck('epcs.id')->map(fn ($id): int => (int) $id)->all();
        if ($epcIds !== []) {
            $openHoldCount = QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count();
            if ($openHoldCount < count($epcIds)) {
                $this->openForCase(
                    $case,
                    $epcIds,
                    'Illegitimate disposition — physical segregation required.',
                    $actor,
                    $case->document,
                );
                $case->refresh();
            }
        }

        $case->forceFill([
            'disposition' => ExceptionDisposition::Illegitimate->value,
            'serials_affected' => $this->countOpenHoldsForCase($case),
        ])->save();

        $openCount = QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count();

        $case->logActivity(
            ExceptionActivityKind::Resolution,
            $actor,
            "Disposition: Illegitimate. Holds kept open ({$openCount}). Next: notify FDA/partners within 24h (Form FDA 3911). ".$notes,
            ExceptionActivityVisibility::Internal,
            [
                'disposition' => ExceptionDisposition::Illegitimate->value,
                'open_holds' => $openCount,
                'fda_3911_checklist' => true,
            ],
        );

        return $case->fresh() ?? $case;
    }

    /**
     * Create or reuse a SUSPECT_PRODUCT case for Find/Recall quarantine, open holds.
     *
     * @param  list<int>  $epcIds
     */
    public function quarantineFromFindRecall(
        array $epcIds,
        string $reason,
        ?User $actor = null,
        ?EpcisDocument $document = null,
    ): ExceptionCase {
        $type = ExceptionType::query()
            ->where('code', 'SUSPECT_PRODUCT')
            ->where('is_active', true)
            ->first()
            ?? $this->exceptions->resolveType('SUSPECT_PRODUCT');

        $case = DB::transaction(function () use ($type, $epcIds, $reason, $actor, $document): ExceptionCase {
            $case = $this->exceptions->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document?->getKey(),
                'trading_partner_id' => $document?->trading_partner_id,
                'title' => 'Quarantine · '.Str::limit($reason, 80),
                'description' => $reason,
                'severity' => ExceptionSeverity::Critical->value,
                'status' => ExceptionStatus::New->value,
            ], $epcIds, $actor);

            $this->openForCase($case, $epcIds, $reason, $actor, $document);

            return $case->fresh(['type', 'epcs']) ?? $case;
        });

        if ($actor !== null && $case->status === ExceptionStatus::New) {
            $this->exceptions->transition($case, ExceptionStatus::Triaged, $actor, 'Auto-triaged for quarantine.');
            $case->refresh();
            $this->exceptions->transition($case, ExceptionStatus::Investigating, $actor, 'Quarantine opened from Find / Recall.');
        }

        return $case->fresh(['type', 'epcs']) ?? $case;
    }

    public function countOpenHoldsForCase(ExceptionCase $case): int
    {
        $epcIds = $case->epcs()->pluck('epcs.id')->all();

        if ($epcIds === []) {
            return QuarantineHold::query()->open()->where('exception_id', $case->getKey())->count();
        }

        return QuarantineHold::query()
            ->open()
            ->where(function ($query) use ($case, $epcIds): void {
                $query->where('exception_id', $case->getKey())
                    ->orWhereIn('epc_id', $epcIds);
            })
            ->count();
    }

    public function refreshSerialsAffected(ExceptionCase $case): void
    {
        $openHolds = $this->countOpenHoldsForCase($case);

        $case->forceFill([
            'serials_affected' => $openHolds > 0 ? $openHolds : $case->epcs()->count(),
        ])->save();
    }
}

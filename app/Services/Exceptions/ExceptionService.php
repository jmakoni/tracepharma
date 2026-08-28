<?php

namespace App\Services\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionAction;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionSlaRule;
use App\Models\Exceptions\ExceptionType;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Exceptions\AssortmentFromCatalog;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ExceptionService
{
    /** @var list<string> */
    private const CREATE_ATTRIBUTES = [
        'exception_type_id',
        'document_id',
        'event_id',
        'trading_partner_id',
        'site_id',
        'compensating_document_id',
        'title',
        'description',
        'severity',
        'status',
        'assigned_to',
        'serials_affected',
    ];

    /**
     * Promote an ingest signal to an investigation case (idempotent).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createFromSignal(EpcisException $signal, array $overrides = [], ?User $actor = null): ExceptionCase
    {
        if ($signal->case_id !== null) {
            $case = ExceptionCase::query()->findOrFail($signal->case_id);
            $this->syncSignalEpcs($case, $signal);

            return $case->fresh(['type', 'epcs']) ?? $case;
        }

        return DB::transaction(function () use ($signal, $overrides, $actor): ExceptionCase {
            $signal = EpcisException::query()
                ->whereKey($signal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($signal->case_id !== null) {
                $case = ExceptionCase::query()->findOrFail($signal->case_id);
                $this->syncSignalEpcs($case, $signal);

                return $case->fresh(['type', 'epcs']) ?? $case;
            }

            $signal->loadMissing('document');

            // Reprocess often recreates the same UNKNOWN_* signal after a case was already
            // resolved/closed. Reattach to that terminal case instead of opening a duplicate.
            $terminal = $this->findMatchingTerminalCase($signal);
            if ($terminal !== null) {
                $signal->forceFill([
                    'case_id' => $terminal->getKey(),
                    'status' => 'resolved',
                    'resolved_at' => $signal->resolved_at ?? now(),
                ])->save();

                $this->syncSignalEpcs($terminal, $signal);

                $terminal->logActivity(
                    ExceptionActivityKind::System,
                    $actor,
                    'Recreated ingest signal #'.$signal->getKey().' linked to this resolved/closed case (no new case opened).',
                    ExceptionActivityVisibility::Internal,
                    ['epcis_exception_id' => $signal->getKey()],
                );

                return $terminal->fresh(['type', 'epcs', 'activities']) ?? $terminal;
            }

            $type = $this->resolveType($signal->exception_type);
            $severity = $overrides['severity']
                ?? $this->mapSignalSeverity($signal->severity);

            $case = $this->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $signal->document_id,
                'event_id' => $signal->event_id,
                'trading_partner_id' => $overrides['trading_partner_id']
                    ?? $signal->document?->trading_partner_id,
                'title' => $overrides['title'] ?? $this->defaultTitle($type, $signal),
                'description' => $overrides['description'] ?? $signal->description,
                'severity' => $severity instanceof ExceptionSeverity ? $severity->value : (string) $severity,
                'status' => ExceptionStatus::New->value,
            ], $this->resolveSignalEpcIds($signal), $actor);

            $signal->forceFill(['case_id' => $case->getKey()])->save();

            $case->logActivity(
                ExceptionActivityKind::System,
                $actor,
                'Opened from ingest signal #'.$signal->getKey().' ('.$signal->exception_type.').',
                ExceptionActivityVisibility::Internal,
                ['epcis_exception_id' => $signal->getKey()],
            );

            return $case->fresh(['type', 'epcs', 'activities']) ?? $case;
        });
    }

    /**
     * Promote every open signal of one document type + status to a single case,
     * attaching failed serials (item-level) or one representative EPC per
     * distinct GTIN/SSCC (file-level).
     */
    public function createFromGroupedSignals(
        EpcisDocument $document,
        string $exceptionType,
        string $status,
        ?User $actor = null,
    ): ExceptionCase {
        $signals = EpcisException::query()
            ->where('document_id', $document->getKey())
            ->where('exception_type', $exceptionType)
            ->where('status', $status)
            ->orderBy('id')
            ->get();

        if ($signals->isEmpty()) {
            throw new InvalidArgumentException('No exception signals match this document group.');
        }

        $existingCaseId = $signals->pluck('case_id')->filter()->first();
        if ($existingCaseId !== null) {
            $case = ExceptionCase::query()->findOrFail((int) $existingCaseId);
            $this->syncGroupEpcs($case, $document, $signals);
            $this->linkSignalsToCase($case, $signals);

            return $case->fresh(['type', 'epcs']) ?? $case;
        }

        $case = $this->createFromSignal($signals->first(), actor: $actor);
        $this->syncGroupEpcs($case, $document, $signals);
        $this->linkSignalsToCase($case, $signals);

        return $case->fresh(['type', 'epcs', 'activities']) ?? $case;
    }

    /**
     * Replace case EPCs with the grouped type's affected identifiers.
     *
     * @param  Collection<int, EpcisException>  $signals
     */
    public function syncGroupEpcs(ExceptionCase $case, EpcisDocument $document, Collection $signals): void
    {
        $epcIds = $this->resolveGroupEpcIds($document, $signals);
        $case->epcs()->sync($epcIds);
        $case->forceFill(['serials_affected' => $case->epcs()->count()])->save();
        app(QuarantineService::class)->refreshSerialsAffected($case);
    }

    /**
     * @param  Collection<int, EpcisException>  $signals
     */
    private function linkSignalsToCase(ExceptionCase $case, Collection $signals): void
    {
        $ids = $signals->modelKeys();
        if ($ids === []) {
            return;
        }

        EpcisException::query()
            ->whereIn('id', $ids)
            ->where(function ($query) use ($case): void {
                $query->whereNull('case_id')
                    ->orWhere('case_id', $case->getKey());
            })
            ->update(['case_id' => $case->getKey()]);
    }

    /**
     * @param  Collection<int, EpcisException>  $signals
     * @return list<int>
     */
    public function resolveGroupEpcIds(EpcisDocument $document, Collection $signals): array
    {
        $itemLevel = $signals->contains(fn (EpcisException $signal): bool => $signal->epc_id !== null || $signal->event_id !== null);

        if ($itemLevel) {
            $ids = [];
            foreach ($signals as $signal) {
                if ($signal->epc_id !== null) {
                    $ids[] = (int) $signal->epc_id;
                    $gtin = (string) (Epc::query()->whereKey((int) $signal->epc_id)->value('gtin14') ?? '');
                    if ($gtin !== '') {
                        $ids = [...$ids, ...$this->resolveDocumentEpcIdsByGtin((int) $document->getKey(), $gtin)];
                    }
                }
                if ($signal->event_id !== null) {
                    $ids = [...$ids, ...$this->resolveEventEpcListIds((int) $signal->event_id)];
                }
            }

            return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
        }

        $descriptionGtinIds = $this->resolveDescriptionGtinEpcIds($document, $signals);
        if ($descriptionGtinIds !== []) {
            return $descriptionGtinIds;
        }

        return $this->resolveCompactFileIdentifierEpcIds($document);
    }

    /**
     * One representative EPC per distinct GTIN and SSCC in the file.
     *
     * @return list<int>
     */
    private function resolveCompactFileIdentifierEpcIds(EpcisDocument $document): array
    {
        $rows = $document->epcsQuery()->get(['epcs.id', 'epcs.gtin14', 'epcs.sscc18']);

        $byGtin = [];
        $bySscc = [];
        foreach ($rows as $row) {
            $gtin = (string) ($row->gtin14 ?? '');
            if ($gtin !== '' && ! isset($byGtin[$gtin])) {
                $byGtin[$gtin] = (int) $row->id;
            }

            $sscc = (string) ($row->sscc18 ?? '');
            if ($sscc !== '' && ! isset($bySscc[$sscc])) {
                $bySscc[$sscc] = (int) $row->id;
            }
        }

        return array_values(array_unique(array_filter(
            [...array_values($byGtin), ...array_values($bySscc)],
            fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * Attach EPCs implied by an ingest signal (idempotent).
     */
    public function syncSignalEpcs(ExceptionCase $case, EpcisException $signal): void
    {
        $epcIds = $this->resolveSignalEpcIds($signal);
        if ($epcIds === []) {
            return;
        }

        $case->epcs()->syncWithoutDetaching($epcIds);

        app(QuarantineService::class)->refreshSerialsAffected($case);
    }

    /**
     * @return list<int>
     */
    public function resolveSignalEpcIds(EpcisException $signal): array
    {
        $ids = [];

        if ($signal->epc_id !== null) {
            $ids[] = (int) $signal->epc_id;
        }

        // Event-scoped findings (e.g. MIXED_PACKAGING_LEVELS) often omit epc_id; attach
        // every epcList member of the offending event so the case EPC relation is populated.
        if ($signal->event_id !== null) {
            $ids = [...$ids, ...$this->resolveEventEpcListIds((int) $signal->event_id)];
        }

        if (
            $signal->exception_type === 'incomplete_product_master_data'
            && $signal->document_id !== null
        ) {
            $ids = [...$ids, ...$this->resolveIncompleteProductMasterDataEpcIds($signal)];
        }

        // UNKNOWN_GTIN findings are one-per-GTIN without epc_id/event_id — attach matching
        // document SGTINs so "Affected EPCs" is not empty after promote.
        if ($ids === [] && $signal->document_id !== null) {
            foreach (ExceptionCorrectionProfile::extractGtinsFromDescription($signal->description) as $gtin) {
                $ids = [...$ids, ...$this->resolveDocumentEpcIdsByGtin((int) $signal->document_id, $gtin)];
            }
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<int>
     */
    private function resolveEventEpcListIds(int $eventId): array
    {
        if (! Schema::hasTable('event_epcs')) {
            return [];
        }

        return DB::table('event_epcs')
            ->where('event_id', $eventId)
            ->whereIn('role', ['epcList', 'childEPC', 'parentID'])
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EpcisException>  $signals
     * @return list<int>
     */
    private function resolveDescriptionGtinEpcIds(EpcisDocument $document, Collection $signals): array
    {
        $gtins = [];
        foreach ($signals as $signal) {
            $gtins = [...$gtins, ...ExceptionCorrectionProfile::extractGtinsFromDescription($signal->description)];
        }

        $gtins = array_values(array_unique($gtins));
        if ($gtins === []) {
            return [];
        }

        $ids = [];
        foreach ($gtins as $gtin) {
            $ids = [...$ids, ...$this->resolveDocumentEpcIdsByGtin((int) $document->getKey(), $gtin)];
        }

        return array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<int>
     */
    private function resolveDocumentEpcIdsByGtin(int $documentId, string $gtin): array
    {
        if (! Schema::hasTable('document_epcs') || ! Schema::hasTable('epcs')) {
            return [];
        }

        $candidates = AssortmentFromCatalog::gtinLookupCandidates($gtin);
        if ($candidates === []) {
            return [];
        }

        return DB::table('document_epcs as de')
            ->join('epcs', 'epcs.id', '=', 'de.epc_id')
            ->where('de.document_id', $documentId)
            ->where('epcs.epc_type', 'sgtin')
            ->whereIn('epcs.gtin14', $candidates)
            ->distinct()
            ->pluck('epcs.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Match document EPCs to idpat patterns mentioned in the signal description.
     *
     * @return list<int>
     */
    private function resolveIncompleteProductMasterDataEpcIds(EpcisException $signal): array
    {
        if (! preg_match_all(
            '/urn:epc:idpat:sgtin:(\d+)\.(\d+)\.\*/',
            (string) $signal->description,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        $ids = [];

        foreach ($matches as $match) {
            $prefix = 'urn:epc:id:sgtin:'.$match[1].'.'.$match[2].'.';

            $chunk = Epc::query()
                ->whereIn('id', function ($query) use ($signal): void {
                    $query->select('epc_id')
                        ->from('document_epcs')
                        ->where('document_id', $signal->document_id);
                })
                ->where('epc_uri', 'like', $prefix.'%')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $ids = [...$ids, ...$chunk];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $epcIds
     */
    public function create(array $attributes, array $epcIds = [], ?User $actor = null): ExceptionCase
    {
        $attributes = Arr::only($attributes, self::CREATE_ATTRIBUTES);

        $typeId = $attributes['exception_type_id'] ?? null;

        if ($typeId === null) {
            throw new InvalidArgumentException('exception_type_id is required.');
        }

        /** @var ExceptionType $type */
        $type = ExceptionType::query()->findOrFail($typeId);

        $severity = ExceptionSeverity::from(
            (string) ($attributes['severity'] ?? $type->default_severity->value),
        );

        if (! array_key_exists('site_id', $attributes) && ! empty($attributes['document_id'])) {
            $document = EpcisDocument::query()->find($attributes['document_id']);
            if ($document?->ship_to_site_id !== null) {
                $attributes['site_id'] = $document->ship_to_site_id;
            }
        }

        $case = ExceptionCase::query()->create([
            ...$attributes,
            'exception_type_id' => $type->getKey(),
            'severity' => $severity,
            'status' => ExceptionStatus::from((string) ($attributes['status'] ?? ExceptionStatus::New->value)),
            'title' => $attributes['title'] ?? $type->name,
            'serials_affected' => $attributes['serials_affected'] ?? count($epcIds),
        ]);

        if ($epcIds !== []) {
            $case->epcs()->syncWithoutDetaching($epcIds);
            $case->forceFill(['serials_affected' => $case->epcs()->count()])->save();
        }

        $this->applySla($case);

        $case->logActivity(
            ExceptionActivityKind::System,
            $actor,
            'Exception case created.',
            ExceptionActivityVisibility::Internal,
        );

        $fresh = $case->fresh() ?? $case;
        app(ExceptionNotificationDispatcher::class)->dispatchCreated($fresh);

        return $fresh;
    }

    public function transition(
        ExceptionCase $case,
        ExceptionStatus $to,
        ?User $actor = null,
        ?string $notes = null,
    ): ExceptionCase {
        $from = $case->status;

        if (! $from->allowsTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from {$from->label()} to {$to->label()}.",
            ]);
        }

        if ($to === ExceptionStatus::Resolved || $to === ExceptionStatus::Closed) {
            $this->assertNoBlockingOpenHolds($case, $to === ExceptionStatus::Closed ? 'close' : 'resolve');
        }

        $updates = ['status' => $to];

        if ($case->first_response_at === null && $from === ExceptionStatus::New) {
            $updates['first_response_at'] = now();
        }

        if ($to === ExceptionStatus::Resolved) {
            $updates['resolved_at'] = now();
            $updates['closed_at'] = null;
        }

        if ($to === ExceptionStatus::Closed) {
            $updates['closed_at'] = now();
            $updates['resolved_at'] = $case->resolved_at ?? now();
        }

        if ($to === ExceptionStatus::Investigating && $from === ExceptionStatus::Resolved) {
            $updates['resolved_at'] = null;
            $updates['closed_at'] = null;
        }

        $case->fill($updates)->save();

        $case->logActivity(
            ExceptionActivityKind::StatusChange,
            $actor,
            $notes ?? "Status changed from {$from->label()} to {$to->label()}.",
            ExceptionActivityVisibility::Internal,
            ['from' => $from->value, 'to' => $to->value],
        );

        return $case->fresh() ?? $case;
    }

    public function assign(ExceptionCase $case, User $assignee, User $actor, bool $notify = true): ExceptionCase
    {
        $case->forceFill([
            'assigned_to' => $assignee->getKey(),
            'assigned_at' => now(),
            'first_response_at' => $case->first_response_at ?? now(),
        ])->save();

        if ($case->status === ExceptionStatus::New) {
            $this->transition($case, ExceptionStatus::Triaged, $actor, 'Auto-triaged on assignment.');
        }

        $case->logActivity(
            ExceptionActivityKind::Assignment,
            $actor,
            'Assigned to '.$assignee->name.'.',
            ExceptionActivityVisibility::Internal,
            ['assigned_to' => $assignee->getKey()],
        );

        $fresh = $case->fresh() ?? $case;

        if ($notify) {
            app(ExceptionNotificationDispatcher::class)->dispatchUpdated($fresh, 'assigned', $actor);
        }

        return $fresh;
    }

    public function resolve(
        ExceptionCase $case,
        User $actor,
        int $rootCauseId,
        int $resolutionActionId,
        string $resolutionNotes,
    ): ExceptionCase {
        if (blank($resolutionNotes)) {
            throw ValidationException::withMessages([
                'resolution_notes' => 'Resolution notes are required.',
            ]);
        }

        $actionCode = ExceptionAction::query()->whereKey($resolutionActionId)->value('code');

        // Resolver always owns the case (reassign if someone else was assigned).
        if ((int) $case->assigned_to !== (int) $actor->getKey()) {
            $this->assign($case, $actor, $actor, notify: false);
            $case->refresh();
        }

        $case->forceFill([
            'root_cause_id' => $rootCauseId,
            'resolution_action_id' => $resolutionActionId,
            'resolution_notes' => $resolutionNotes,
        ])->save();

        if ($actionCode === 'quarantine_product') {
            return $this->resolveWithQuarantineProduct($case, $actor, $rootCauseId, $resolutionActionId, $resolutionNotes);
        }

        $this->assertNoBlockingOpenHolds($case, 'resolve');

        if ($case->status !== ExceptionStatus::Resolved) {
            if (! $case->status->allowsTransitionTo(ExceptionStatus::Resolved)) {
                if ($case->status === ExceptionStatus::New) {
                    $this->transition($case, ExceptionStatus::Triaged, $actor);
                    $case->refresh();
                }
                if ($case->status->allowsTransitionTo(ExceptionStatus::Investigating)) {
                    $this->transition($case, ExceptionStatus::Investigating, $actor);
                    $case->refresh();
                }
            }

            $this->transition($case, ExceptionStatus::Resolved, $actor, 'Marked resolved.');
        }

        $case->logActivity(
            ExceptionActivityKind::Resolution,
            $actor,
            $resolutionNotes,
            ExceptionActivityVisibility::Internal,
            [
                'root_cause_id' => $rootCauseId,
                'resolution_action_id' => $resolutionActionId,
            ],
        );

        $this->closeMatchingDocumentSignals($case);

        $fresh = $case->fresh() ?? $case;
        app(ExceptionNotificationDispatcher::class)->dispatchUpdated($fresh, 'resolved', $actor);

        return $fresh;
    }

    /**
     * Quarantine resolution opens holds before any terminal status — the case stays
     * investigating until holds are cleared or released.
     */
    private function resolveWithQuarantineProduct(
        ExceptionCase $case,
        User $actor,
        int $rootCauseId,
        int $resolutionActionId,
        string $resolutionNotes,
    ): ExceptionCase {
        $epcIds = $case->epcs()->pluck('epcs.id')->map(fn ($id): int => (int) $id)->all();
        if ($epcIds !== []) {
            app(QuarantineService::class)->openForCase(
                $case,
                $epcIds,
                'Quarantine product resolution: '.$resolutionNotes,
                $actor,
                $case->document,
            );
        }

        if ($case->status === ExceptionStatus::New) {
            $this->transition($case, ExceptionStatus::Triaged, $actor);
            $case->refresh();
        }

        if ($case->status->allowsTransitionTo(ExceptionStatus::Investigating)) {
            $this->transition(
                $case,
                ExceptionStatus::Investigating,
                $actor,
                'Quarantine holds opened; investigation continues.',
            );
            $case->refresh();
        }

        $case->logActivity(
            ExceptionActivityKind::Resolution,
            $actor,
            $resolutionNotes,
            ExceptionActivityVisibility::Internal,
            [
                'root_cause_id' => $rootCauseId,
                'resolution_action_id' => $resolutionActionId,
            ],
        );

        $fresh = $case->fresh() ?? $case;
        app(ExceptionNotificationDispatcher::class)->dispatchUpdated($fresh, 'quarantine_opened', $actor);

        return $fresh;
    }

    public function escalate(ExceptionCase $case, User $actor, ?string $notes = null): ExceptionCase
    {
        if (! $case->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'Closed cases cannot be escalated.',
            ]);
        }

        $note = $notes ?? 'Escalated for compliance review.';

        if ($case->status === ExceptionStatus::New) {
            $this->transition($case, ExceptionStatus::Triaged, $actor, $note);
            $case->refresh();
        }

        if ($case->status === ExceptionStatus::Triaged) {
            $this->transition($case, ExceptionStatus::Investigating, $actor, $note);
            $case->refresh();
        }

        if ($case->status->allowsTransitionTo(ExceptionStatus::PendingApproval)) {
            $this->transition($case, ExceptionStatus::PendingApproval, $actor, $note);
            $case->refresh();
        }

        $fresh = $case->fresh() ?? $case;
        app(ExceptionNotificationDispatcher::class)->dispatchEscalated($fresh);

        return $fresh;
    }

    /**
     * Close open ingest signals (epcis_exceptions) that this resolved case addresses:
     * signals directly linked via case_id, plus — when a fingerprint (GTIN/GLN) can be
     * extracted from the case — unlinked open signals of the same type on the same
     * document that reference the same identifier.
     *
     * Public so correction flows can re-run after EPCIS re-process (which recreates
     * open signals after resolve).
     */
    public function closeMatchingDocumentSignals(ExceptionCase $case): int
    {
        $case->loadMissing('type');

        $now = now();
        $closed = 0;

        $closed += EpcisException::query()
            ->where('case_id', $case->getKey())
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => $now]);

        if ($case->document_id === null || $case->type === null) {
            return $closed;
        }

        $signalTypes = $this->matchingSignalTypes($case->type->code);
        if ($signalTypes === []) {
            return $closed;
        }

        $fingerprint = $this->extractFingerprint($case->description);

        $candidates = EpcisException::query()
            ->where('document_id', $case->document_id)
            ->where('status', 'open')
            ->whereIn('exception_type', $signalTypes)
            ->get();

        foreach ($candidates as $signal) {
            if ($fingerprint !== null) {
                if ($fingerprint !== $this->extractFingerprint($signal->description)) {
                    continue;
                }
            } elseif ($case->event_id !== null) {
                if ((int) $signal->event_id !== (int) $case->event_id) {
                    continue;
                }
            } elseif ((string) $case->description !== (string) $signal->description) {
                continue;
            }

            $signal->forceFill([
                'status' => 'resolved',
                'resolved_at' => $now,
                // Prefer the terminal case that already addressed this fingerprint.
                'case_id' => $signal->case_id ?? $case->getKey(),
            ])->save();
            $closed++;
        }

        return $closed;
    }

    /**
     * After EPCIS re-validation recreates open signals, re-close any that match
     * resolved/closed cases on the same document (by case_id or GTIN/GLN fingerprint).
     */
    public function closeMatchingSignalsForDocument(int $documentId): int
    {
        $cases = ExceptionCase::query()
            ->where('document_id', $documentId)
            ->whereIn('status', [
                ExceptionStatus::Resolved->value,
                ExceptionStatus::Closed->value,
            ])
            ->orderByDesc('id')
            ->get();

        $closed = 0;
        foreach ($cases as $case) {
            $closed += $this->closeMatchingDocumentSignals($case);
        }

        return $closed;
    }

    /**
     * Find a resolved/closed case on the same document that already addresses this signal
     * (same catalog type + GTIN/GLN fingerprint, or identical description when no fingerprint).
     */
    private function findMatchingTerminalCase(EpcisException $signal): ?ExceptionCase
    {
        if ($signal->document_id === null) {
            return null;
        }

        $type = $this->resolveType($signal->exception_type);
        $fingerprint = $this->extractFingerprint($signal->description);

        $candidates = ExceptionCase::query()
            ->where('document_id', $signal->document_id)
            ->where('exception_type_id', $type->getKey())
            ->whereIn('status', [
                ExceptionStatus::Resolved->value,
                ExceptionStatus::Closed->value,
            ])
            ->orderByDesc('id')
            ->get();

        foreach ($candidates as $case) {
            if ($fingerprint !== null) {
                if ($fingerprint === $this->extractFingerprint($case->description)) {
                    return $case;
                }

                continue;
            }

            if ((string) $case->description === (string) $signal->description) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Ingest signals may carry the catalog code directly (e.g. "UNKNOWN_GTIN") or a legacy
     * lowercase alias mapped via {@see self::legacySignalTypeMap()} (e.g.
     * "missing_transaction_statement" for MISSING_DSCSA_STATEMENT).
     *
     * @return list<string>
     */
    private function matchingSignalTypes(string $code): array
    {
        $types = [$code];

        foreach (self::legacySignalTypeMap() as $legacy => $catalogCode) {
            if ($catalogCode === $code) {
                $types[] = $legacy;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Extract a GTIN or GLN "fingerprint" from exception copy so a resolved case only closes
     * the specific unlinked signal it addresses, not every open signal of the same type on
     * the document (e.g. two distinct UNKNOWN_GTIN signals).
     *
     * @return array{0: string, 1: string}|null
     */
    private function extractFingerprint(?string $description): ?array
    {
        $gtin = ExceptionCorrectionProfile::extractGtinFromDescription($description);
        if ($gtin !== null) {
            return ['gtin', ltrim($gtin, '0') ?: '0'];
        }

        $gln = ExceptionCorrectionProfile::extractGlnFromDescription($description);
        if ($gln !== null) {
            return ['gln', ltrim($gln, '0') ?: '0'];
        }

        return null;
    }

    public function close(ExceptionCase $case, User $actor, ?string $notes = null): ExceptionCase
    {
        $this->assertNoBlockingOpenHolds($case, 'close');

        $closeNotes = filled($notes) ? (string) $notes : 'Case closed.';

        if (filled($notes) && blank($case->resolution_notes)) {
            $case->forceFill(['resolution_notes' => $closeNotes])->save();
        }

        return $this->transition($case, ExceptionStatus::Closed, $actor, $closeNotes);
    }

    private function assertNoBlockingOpenHolds(ExceptionCase $case, string $action): void
    {
        if (! $case->hasBlockingOpenQuarantineHolds()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => "Cannot {$action} while open quarantine holds remain. Clear for distribution or release quarantine first.",
        ]);
    }

    public function addComment(
        ExceptionCase $case,
        User $actor,
        string $body,
        ExceptionActivityVisibility $visibility = ExceptionActivityVisibility::Internal,
    ): ExceptionCase {
        if (blank($body)) {
            throw ValidationException::withMessages([
                'body' => 'Comment cannot be empty.',
            ]);
        }

        if ($case->first_response_at === null) {
            $case->forceFill(['first_response_at' => now()])->save();
        }

        $case->logActivity(
            ExceptionActivityKind::Comment,
            $actor,
            $body,
            $visibility,
        );

        return $case;
    }

    public function applySla(ExceptionCase $case): void
    {
        $severity = $case->severity;

        $rule = ExceptionSlaRule::query()
            ->where('is_active', true)
            ->where('severity', $severity->value)
            ->where('exception_type_id', $case->exception_type_id)
            ->first();

        $rule ??= ExceptionSlaRule::query()
            ->where('is_active', true)
            ->where('severity', $severity->value)
            ->whereNull('exception_type_id')
            ->first();

        if ($rule === null) {
            return;
        }

        $case->forceFill([
            'due_at' => now()->addHours($rule->resolve_hours),
        ])->save();
    }

    public function mapSignalSeverity(?string $signalSeverity): ExceptionSeverity
    {
        return match (strtolower((string) $signalSeverity)) {
            'critical' => ExceptionSeverity::Critical,
            'error', 'high' => ExceptionSeverity::High,
            'warning', 'medium' => ExceptionSeverity::Medium,
            'info', 'low' => ExceptionSeverity::Low,
            default => ExceptionSeverity::Medium,
        };
    }

    public function resolveType(?string $signalTypeCode): ExceptionType
    {
        $raw = filled($signalTypeCode) ? trim((string) $signalTypeCode) : 'UNCLASSIFIED';
        $code = self::legacySignalTypeMap()[$raw] ?? $raw;

        $type = ExceptionType::query()->where('code', $code)->where('is_active', true)->first();

        if ($type !== null) {
            return $type;
        }

        $fromCatalog = ExceptionTypeSeeder::ensure($code);

        if ($fromCatalog !== null) {
            return $fromCatalog;
        }

        return ExceptionType::query()->firstOrCreate(
            ['code' => 'UNCLASSIFIED'],
            [
                'name' => 'Unclassified',
                'category' => 'system',
                'description' => 'Fallback type for unmapped ingest signals.',
                'default_severity' => ExceptionSeverity::Medium,
                'is_active' => true,
            ],
        );
    }

    /**
     * Ingest still writes lowercase signal codes on epcis_exceptions.exception_type.
     * Map those to the SCREAMING_SNAKE exception_types catalog.
     *
     * @return array<string, string>
     */
    public static function legacySignalTypeMap(): array
    {
        return [
            'ingest_failure' => 'INGESTION_PARSE_ERROR',
            'missing_transaction_statement' => 'MISSING_DSCSA_STATEMENT',
            'dropped_epc_uris' => 'INVALID_EPC_URI',
            'atp_soft_warning' => 'MASTER_DATA_SYNC_LAG',
            'sbdh_source_owning_party_mismatch' => 'SBDH_SOURCE_OWNING_PARTY_MISMATCH',
            'missing_biz_transaction' => 'MISSING_BIZ_TRANSACTION',
            'incomplete_product_master_data' => 'MASTER_DATA_SYNC_LAG',
            'unclassified' => 'UNCLASSIFIED',
        ];
    }

    private function defaultTitle(ExceptionType $type, EpcisException $signal): string
    {
        $suffix = $signal->document_id ? ' · Document #'.$signal->document_id : '';

        return $type->name.$suffix;
    }
}

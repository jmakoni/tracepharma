<?php

namespace App\Filament\App\Resources\Exceptions\Pages;

use App\Actions\Exceptions\SendDscsaExceptionEmail;
use App\Actions\Fda3911\PrefillFda3911Report;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionDisposition;
use App\Enums\ExceptionStatus;
use App\Filament\App\Resources\Exceptions\Actions\CorrectDocumentActions;
use App\Filament\App\Resources\Exceptions\Actions\CorrectUnknownGlnAction;
use App\Filament\App\Resources\Exceptions\Actions\CorrectUnknownGtinAction;
use App\Filament\App\Resources\Exceptions\Actions\CorrectWaiveAction;
use App\Filament\App\Resources\Exceptions\Actions\RequestPartnerCorrectionAction;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\Fda3911Reports\Fda3911ReportResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Exceptions\ExceptionAction;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionRootCause;
use App\Models\Fda3911Report;
use App\Models\TradingPartner;
use App\Models\User;
use App\Policies\TradingPartnerPolicy;
use App\Services\Exceptions\ExceptionService;
use App\Services\Quarantine\QuarantineService;
use App\Services\Quarantine\SupplierPortalService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Exceptions\ExceptionCorrectionProfile;
use App\Support\Filament\ProseEditor;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;
use Throwable;

class ViewException extends ViewRecord
{
    protected static string $resource = ExceptionResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->getRecord()->loadMissing([
            'type',
            'document',
            'tradingPartner',
            'assignee',
            'rootCause',
            'resolutionAction',
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var ExceptionCase $record */
        $record = $this->getRecord();

        $status = $record->status?->label() ?? '—';
        $severity = $record->severity?->label() ?? '—';
        $due = $record->isOverdue()
            ? 'Overdue'
            : ($record->due_at?->toDayDateTimeString() ?? 'No due date');
        $holds = $record->quarantineHolds()->open()->count();
        $quarantine = $holds > 0 ? " · {$holds} quarantined" : '';
        $disposition = $record->disposition instanceof ExceptionDisposition
            ? ' · '.$record->disposition->label()
            : '';

        return "{$status} · {$severity} · {$due}{$quarantine}{$disposition}";
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            CorrectUnknownGtinAction::make($this),
            CorrectUnknownGlnAction::make($this),
            CorrectDocumentActions::makeGroup($this),
            RequestPartnerCorrectionAction::make($this),
            CorrectWaiveAction::make($this),
            Action::make('escalate')
                ->label('Escalate')
                ->icon(Heroicon::OutlinedArrowUp)
                ->color('danger')
                ->visible(function (): bool {
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    return $record->status?->isOpen() === true
                        && $record->status !== ExceptionStatus::PendingApproval;
                })
                ->requiresConfirmation()
                ->modalHeading('Escalate for compliance review')
                ->modalDescription('Moves this case toward pending approval and notifies owners and platform support.')
                ->action(function (): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    try {
                        app(ExceptionService::class)->escalate($record, $actor);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Escalation failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Case escalated')
                        ->success()
                        ->send();
                }),
            Action::make('sendSupplierEmail')
                ->label('Email supplier')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('info')
                ->visible(function (): bool {
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    return $record->status?->isOpen() === true
                        && $record->trading_partner_id !== null
                        && filled($record->tradingPartner?->email);
                })
                ->requiresConfirmation()
                ->modalHeading('Send DSCSA exception email')
                ->modalDescription('Sends the supplier a link to the exception portal.')
                ->action(function (): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    $result = app(SendDscsaExceptionEmail::class)->execute($record, $actor);

                    if (! ($result['sent'] ?? false)) {
                        Notification::make()
                            ->title('Supplier email failed')
                            ->body($result['error'] ?? 'Unable to send.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Supplier email sent')
                        ->success()
                        ->send();
                }),
            Action::make('assignToMe')
                ->label('Assign to me')
                ->icon(Heroicon::OutlinedUserPlus)
                ->visible(function (): bool {
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    return $record->status?->isOpen() === true
                        && (int) $record->assigned_to !== (int) auth()->id();
                })
                ->action(function (): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    try {
                        app(ExceptionService::class)->assign($record, $actor, $actor);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Assignment failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Assigned to you')
                        ->success()
                        ->send();
                }),
            Action::make('changeStatus')
                ->label('Change status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(function (): bool {
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    return $record->status->allowedTransitions() !== [];
                })
                ->schema([
                    Select::make('status')
                        ->label('Status')
                        ->options(function (): array {
                            /** @var ExceptionCase $record */
                            $record = $this->getRecord();

                            return collect($record->status->allowedTransitions())
                                ->reject(fn (ExceptionStatus $status): bool => $status === ExceptionStatus::Resolved
                                    || $status === ExceptionStatus::Closed)
                                ->mapWithKeys(fn (ExceptionStatus $status): array => [
                                    $status->value => $status->label(),
                                ])
                                ->all();
                        })
                        ->required(),
                    ProseEditor::make('notes')
                        ->label('Notes')
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    try {
                        $to = ExceptionStatus::from((string) $data['status']);

                        if ($to === ExceptionStatus::Resolved || $to === ExceptionStatus::Closed) {
                            throw ValidationException::withMessages([
                                'status' => 'Use the Resolve or Close actions to set this status.',
                            ]);
                        }

                        app(ExceptionService::class)->transition(
                            $record,
                            $to,
                            $actor,
                            filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Status change failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Status updated')
                        ->success()
                        ->send();
                }),
            Action::make('addInternalComment')
                ->label('Add comment')
                ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                ->schema([
                    ProseEditor::make('body')
                        ->label('Comment')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    try {
                        app(ExceptionService::class)->addComment(
                            $record,
                            $actor,
                            (string) $data['body'],
                            ExceptionActivityVisibility::Internal,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Comment failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Comment added')
                        ->success()
                        ->send();
                }),
            Action::make('addPartnerNote')
                ->label('Partner-visible note')
                ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                ->color('info')
                ->schema([
                    ProseEditor::make('body')
                        ->label('Note')
                        ->required()
                        ->helperText('Visible to trading partners when partner portals are enabled.'),
                ])
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    try {
                        app(ExceptionService::class)->addComment(
                            $record,
                            $actor,
                            (string) $data['body'],
                            ExceptionActivityVisibility::Partner,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Note failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Partner-visible note added')
                        ->success()
                        ->send();
                }),
            ActionGroup::make([
                RegulatoryCompliance::apply(
                    Action::make('openQuarantine')
                        ->label('Open quarantine')
                        ->icon(Heroicon::OutlinedShieldExclamation)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Open electronic quarantine holds')
                        ->modalDescription('Opens a hold for every EPC linked to this case so units cannot be treated as saleable until released.')
                        ->visible(function (): bool {
                            /** @var ExceptionCase $record */
                            $record = $this->getRecord();

                            return $record->epcs()->exists()
                                && $record->status?->isOpen() === true;
                        })
                        ->schema([
                            Textarea::make('reason')
                                ->label('Quarantine reason')
                                ->required()
                                ->rows(3)
                                ->default(fn (): ?string => $this->getRecord()->description)
                                ->maxLength(2000),
                        ])
                        ->action(function (array $data): void {
                            /** @var User $actor */
                            $actor = auth()->user();
                            /** @var ExceptionCase $record */
                            $record = $this->getRecord();
                            $epcIds = $record->epcs()->pluck('epcs.id')->map(fn ($id): int => (int) $id)->all();

                            $opened = app(QuarantineService::class)->openForCase(
                                $record,
                                $epcIds,
                                (string) $data['reason'],
                                $actor,
                                $record->document,
                            );

                            if ($record->status === ExceptionStatus::New) {
                                app(ExceptionService::class)->transition(
                                    $record,
                                    ExceptionStatus::Triaged,
                                    $actor,
                                    'Auto-triaged for quarantine.',
                                );
                                $record->refresh();
                                app(ExceptionService::class)->transition(
                                    $record,
                                    ExceptionStatus::Investigating,
                                    $actor,
                                    'Investigation started with quarantine.',
                                );
                            }

                            $this->refreshRecord();

                            Notification::make()
                                ->title('Quarantine opened')
                                ->body("Opened {$opened} new hold(s).")
                                ->success()
                                ->send();
                        }),
                    'exception_quarantine_open',
                    requireReason: true,
                    existingReasonField: 'reason',
                ),
                Action::make('copySupplierLink')
                    ->label('Copy case link')
                    ->icon(Heroicon::OutlinedLink)
                    ->authorize(fn (): bool => $this->canShareSupplierCaseLink())
                    ->visible(fn (): bool => $this->getRecord()->status?->isOpen() === true
                        && $this->canShareSupplierCaseLink())
                    ->action(function (): void {
                        /** @var ExceptionCase $record */
                        $record = $this->getRecord();
                        $portal = app(QuarantineService::class);
                        $url = $portal->signedSupplierUrl($record);

                        $this->js('window.navigator.clipboard.writeText('.Js::from($url).')');

                        Notification::make()
                            ->title('Supplier case link copied')
                            ->body('Expires in '.$portal->linkTtlDays().' days.')
                            ->success()
                            ->send();
                    }),
                Action::make('copySupplierPortalLink')
                    ->label('Copy supplier portal link')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->record(fn (): ?TradingPartner => $this->getRecord()->tradingPartner)
                    ->authorize('managePortalLink')
                    ->visible(fn (): bool => $this->getRecord()->trading_partner_id !== null)
                    ->action(function (): void {
                        /** @var ExceptionCase $record */
                        $record = $this->getRecord();
                        $partner = $record->tradingPartner;
                        if ($partner === null) {
                            Notification::make()
                                ->title('No trading partner')
                                ->body('Assign a trading partner before sharing the supplier portal.')
                                ->warning()
                                ->send();

                            return;
                        }

                        if (! $partner->is_active) {
                            Notification::make()
                                ->title('Trading partner is inactive')
                                ->body('Reactivate '.$partner->name.' before sharing their exception portal.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $portal = app(SupplierPortalService::class);
                        $url = $portal->signedPartnerExceptionsUrl($partner);

                        $this->js('window.navigator.clipboard.writeText('.Js::from($url).')');

                        Notification::make()
                            ->title('Supplier portal link copied')
                            ->body('Lists open exceptions for '.$partner->name.'. Expires in '.$portal->linkTtlDays().' days.')
                            ->success()
                            ->send();
                    }),
                RegulatoryCompliance::apply(
                    Action::make('releaseQuarantine')
                        ->label('Release quarantine')
                        ->icon(Heroicon::OutlinedLockOpen)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => $this->hasOpenQuarantineHolds()
                            && $this->getRecord()->disposition !== ExceptionDisposition::Illegitimate)
                        ->schema([
                            Textarea::make('reason')
                                ->label('Release reason')
                                ->required()
                                ->rows(3)
                                ->maxLength(2000),
                        ])
                        ->action(function (array $data): void {
                            /** @var User $actor */
                            $actor = auth()->user();
                            /** @var ExceptionCase $record */
                            $record = $this->getRecord();
                            $shipToSiteId = $record->document?->ship_to_site_id !== null
                                ? (int) $record->document->ship_to_site_id
                                : null;

                            if (! SiteAccess::canAccessShipToSite($actor, $shipToSiteId)) {
                                Notification::make()
                                    ->title('Not authorized')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                $released = app(QuarantineService::class)->releaseForCase(
                                    $this->getRecord(),
                                    $actor,
                                    (string) $data['reason'],
                                );
                            } catch (ValidationException $e) {
                                Notification::make()
                                    ->title('Release failed')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }
                            $this->refreshRecord();
                            Notification::make()
                                ->title('Quarantine released')
                                ->body("Released {$released} hold(s).")
                                ->success()
                                ->send();
                        }),
                    'exception_quarantine_release',
                    requireReason: true,
                    existingReasonField: 'reason',
                ),
                RegulatoryCompliance::apply(
                    Action::make('clearDisposition')
                        ->label('Clear for distribution')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => $this->hasOpenQuarantineHolds()
                            && $this->getRecord()->disposition !== ExceptionDisposition::Illegitimate)
                        ->schema([
                            ProseEditor::make('notes')
                                ->label('Clearance notes')
                                ->required()
                                ->helperText('Investigation summary supporting clearance.'),
                        ])
                        ->action(function (array $data): void {
                            /** @var User $actor */
                            $actor = auth()->user();
                            /** @var ExceptionCase $record */
                            $record = $this->getRecord();
                            $shipToSiteId = $record->document?->ship_to_site_id !== null
                                ? (int) $record->document->ship_to_site_id
                                : null;

                            if (! SiteAccess::canAccessShipToSite($actor, $shipToSiteId)) {
                                Notification::make()
                                    ->title('Not authorized')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                app(QuarantineService::class)->clearForDistribution(
                                    $this->getRecord(),
                                    $actor,
                                    (string) $data['notes'],
                                );
                            } catch (ValidationException $e) {
                                Notification::make()->title('Clearance failed')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()->send();

                                return;
                            }
                            $this->refreshRecord();
                            Notification::make()->title('Cleared for distribution')->success()->send();
                        }),
                    'exception_clear',
                    requireReason: true,
                    existingReasonField: 'notes',
                ),
                RegulatoryCompliance::apply(
                    Action::make('illegitimateDisposition')
                        ->label('Mark illegitimate')
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => $this->hasOpenQuarantineHolds()
                            && $this->getRecord()->disposition !== ExceptionDisposition::Illegitimate)
                        ->schema([
                            ProseEditor::make('notes')
                                ->label('Illegitimate determination notes')
                                ->required()
                                ->helperText('Keeps holds open. Notify FDA and immediate trading partners within 24 hours (Form FDA 3911).'),
                        ])
                        ->action(function (array $data): void {
                            /** @var User $actor */
                            $actor = auth()->user();
                            try {
                                app(QuarantineService::class)->markIllegitimate(
                                    $this->getRecord(),
                                    $actor,
                                    (string) $data['notes'],
                                );
                            } catch (ValidationException $e) {
                                Notification::make()->title('Disposition failed')
                                    ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                                    ->danger()->send();

                                return;
                            }
                            $this->refreshRecord();
                            Notification::make()
                                ->title('Marked illegitimate')
                                ->body('Holds remain open. Complete Form FDA 3911 within 24 hours.')
                                ->warning()
                                ->send();
                        }),
                    'exception_illegitimate',
                    requireReason: true,
                    existingReasonField: 'notes',
                ),
                Action::make('prefillFda3911')
                    ->label('Draft FDA 3911')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('warning')
                    ->authorize(fn (): bool => JobRoleAccess::allows(Permissions::NavCompliance))
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsComplianceCases()
                        && $this->getRecord()->disposition === ExceptionDisposition::Illegitimate)
                    ->action(function (): void {
                        /** @var User $actor */
                        $actor = auth()->user();
                        /** @var ExceptionCase $record */
                        $record = $this->getRecord();

                        $existing = Fda3911Report::query()
                            ->where('exception_id', $record->getKey())
                            ->latest('id')
                            ->first();

                        if ($existing !== null) {
                            $this->redirect(Fda3911ReportResource::getUrl('view', ['record' => $existing], panel: 'app'));

                            return;
                        }

                        $report = app(PrefillFda3911Report::class)->execute($actor, $record);

                        Notification::make()
                            ->title('FDA 3911 draft created')
                            ->body('Review circumstances and submit within 24 hours.')
                            ->success()
                            ->send();

                        $this->redirect(Fda3911ReportResource::getUrl('view', ['record' => $report], panel: 'app'));
                    }),
            ])
                ->label(fn (): string => ExceptionCorrectionProfile::forCase($this->getRecord())->emphasizeQuarantine()
                    ? 'Quarantine & investigate'
                    : 'Quarantine')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color(fn (): string => ExceptionCorrectionProfile::forCase($this->getRecord())->emphasizeQuarantine()
                    ? 'danger'
                    : 'gray')
                ->button(),
        ];

        $resolveAction = RegulatoryCompliance::apply(
            Action::make('resolve')
                ->label('Resolve')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(function (): bool {
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();
                    $status = $record->status;
                    $profile = ExceptionCorrectionProfile::forCase($record);

                    return $status?->isOpen() === true
                        && ($status === ExceptionStatus::Investigating
                            || $status->allowsTransitionTo(ExceptionStatus::Resolved)
                            || $profile->showsMasterDataProductForm());
                })
                ->disabled(fn (): bool => $this->getRecord()->hasBlockingOpenQuarantineHolds())
                ->tooltip(fn (): ?string => $this->getRecord()->hasBlockingOpenQuarantineHolds()
                    ? 'Clear for distribution or release quarantine before resolving.'
                    : null)
                ->schema([
                    Select::make('root_cause_id')
                        ->label('Root cause')
                        ->options(fn (): array => ExceptionRootCause::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('resolution_action_id')
                        ->label('Resolution action')
                        ->options(function (): array {
                            /** @var ExceptionCase $record */
                            $record = $this->getRecord();
                            $query = ExceptionAction::query()
                                ->where('is_active', true);

                            $typeCode = strtoupper(trim((string) $record->type?->code));
                            // OVER_SHIPMENT must not be closed via waiver — quarantine / investigate.
                            if ($typeCode === 'OVER_SHIPMENT') {
                                $query->where('code', '!=', 'accept_with_waiver');
                            }

                            // Receiving-issues shortages: force non-waiver resolution path.
                            if (
                                $typeCode === 'PARTIAL_SHIPMENT_UNDECLARED'
                                && ! ExceptionCorrectionProfile::showsWaiveForCase($record)
                            ) {
                                $query->whereNotIn('code', ['accept_with_waiver', 'no_action_false_positive']);
                            }

                            return $query
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->required(),
                    ProseEditor::make('resolution_notes')
                        ->label('Resolution notes')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    try {
                        app(ExceptionService::class)->resolve(
                            $record,
                            $actor,
                            (int) $data['root_cause_id'],
                            (int) $data['resolution_action_id'],
                            (string) $data['resolution_notes'],
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Resolve failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Resolve failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Exception resolved')
                        ->success()
                        ->send();
                }),
            'exception_resolve',
            requireReason: true,
            existingReasonField: 'resolution_notes',
        );

        $profile = ExceptionCorrectionProfile::forCase($this->getRecord());
        if ($profile->isSpecialized() && ! $profile->showsMasterDataProductForm()) {
            $actions[] = ActionGroup::make([$resolveAction])
                ->label('Other')
                ->icon(Heroicon::OutlinedEllipsisHorizontal)
                ->button()
                ->color('gray');
        } else {
            $actions[] = $resolveAction;
        }

        $actions[] = RegulatoryCompliance::apply(
            Action::make('close')
                ->label('Close')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();

                    return $record->status === ExceptionStatus::Resolved;
                })
                ->disabled(fn (): bool => $this->getRecord()->hasBlockingOpenQuarantineHolds())
                ->tooltip(fn (): ?string => $this->getRecord()->hasBlockingOpenQuarantineHolds()
                    ? 'Clear for distribution or release quarantine before closing.'
                    : null)
                ->action(function (array $data): void {
                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var ExceptionCase $record */
                    $record = $this->getRecord();
                    $complianceReason = trim((string) ($data['compliance_reason'] ?? ''));

                    try {
                        app(ExceptionService::class)->close(
                            $record,
                            $actor,
                            $complianceReason !== '' ? $complianceReason : null,
                        );
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Close failed')
                            ->body(collect($e->errors())->flatten()->first() ?? $e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Exception closed')
                        ->success()
                        ->send();
                }),
            'exception_close',
            requireReason: true,
        );

        return $actions;
    }

    public function refreshRecord(): void
    {
        $this->getRecord()->refresh()->loadMissing([
            'type',
            'document',
            'tradingPartner',
            'assignee',
            'rootCause',
            'resolutionAction',
        ]);
    }

    private function hasOpenQuarantineHolds(): bool
    {
        /** @var ExceptionCase $record */
        $record = $this->getRecord();

        return $record->quarantineHolds()->open()->exists();
    }

    private function canShareSupplierCaseLink(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $partner = $this->getRecord()->tradingPartner;

        return app(TradingPartnerPolicy::class)->managePortalLink(
            $user,
            $partner ?? new TradingPartner,
        );
    }
}

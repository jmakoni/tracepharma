<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Actions\Integrations\PromoteOutboundConnectionConformance;
use App\Actions\Integrations\RegisterConnectionApprovalRequest;
use App\Enums\ConnectionApprovalStatus;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\OutboundConnection;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Integrations\OutboundConnectionDefaultSync;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

class EditOutboundConnection extends EditRecord
{
    use TransformsConnectionCredentials;

    protected static string $resource = OutboundConnectionResource::class;

    private ?string $securityFingerprintBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->promoteAction(),
            $this->breakGlassAction(),
            DeleteAction::make()
                ->before(function (DeleteAction $action, OutboundConnection $record): void {
                    $open = $record->openShippingSessionCount();

                    if ($open <= 0) {
                        return;
                    }

                    Notification::make()
                        ->title('Connection cannot be deleted')
                        ->body("It is linked to {$open} open ship session".($open === 1 ? '' : 's').'. Complete or cancel those ships first, or deactivate the connection.')
                        ->warning()
                        ->persistent()
                        ->send();

                    $action->cancel();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillDedicatedCredentialFields($data, $this->record->credentials ?? []);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['conformance_state']);

        /** @var OutboundConnection $record */
        $record = $this->record;
        // Form getState() may already have written scalar attributes onto $record;
        // fingerprint the persisted row so Approved re-pend compares pre-edit values.
        $this->securityFingerprintBeforeSave = app(RegisterConnectionApprovalRequest::class)
            ->securityFingerprint($record->fresh() ?? $record);

        $existingSettings = $this->record->settings ?? [];
        $incomingSettings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = array_merge($existingSettings, $incomingSettings);

        return $this->transformOutboundCredentialPairs($data, $this->record->credentials ?? []);
    }

    protected function afterSave(): void
    {
        /** @var OutboundConnection $record */
        $record = $this->record;
        $record->syncTradingPartnerIdFromPartners();
        $record->refresh();
        $record->assertAssignableConfiguration();
        OutboundConnectionDefaultSync::ensureSingleDefault($record->fresh());

        $this->syncApprovalRequest($record->fresh());
    }

    private function syncApprovalRequest(OutboundConnection $record): void
    {
        $registrar = app(RegisterConnectionApprovalRequest::class);
        $fingerprintChanged = $this->securityFingerprintBeforeSave !== null
            && $this->securityFingerprintBeforeSave !== $registrar->securityFingerprint($record);

        // Rejected/suspended always resubmit; Approved only when security-sensitive fields change.
        if ($record->isRejected() || $record->isSuspended() || ($record->isApproved() && $fingerprintChanged)) {
            $record->approval_status = ConnectionApprovalStatus::Pending;
            $record->approval_note = null;
            $record->save();
            $record->refresh();
        }

        if ($record->isPendingApproval()) {
            $registrar->register($record);
        }
    }

    private function promoteAction(): Action
    {
        return Action::make('promoteConformance')
            ->label(function (): string {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $next = $record->conformanceState()->next();

                return $next !== null
                    ? 'Promote to '.$next->label()
                    : 'Promote';
            })
            ->icon('heroicon-o-arrow-up-circle')
            ->color('primary')
            ->visible(function (): bool {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();

                return $record->conformanceState()->next() !== null
                    && auth()->user()?->can('update', $record) === true;
            })
            ->requiresConfirmation()
            ->action(function (): void {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                $this->authorize('update', $record);

                try {
                    app(PromoteOutboundConnectionConformance::class)->promoteOneStep($record, $user);
                    $this->refreshFormData(['conformance_state']);
                    Notification::make()
                        ->title('Conformance promoted')
                        ->body('Now '.$record->fresh()->conformanceState()->label().'.')
                        ->success()
                        ->send();
                } catch (InvalidArgumentException|Throwable $e) {
                    Notification::make()
                        ->title('Promotion failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function breakGlassAction(): Action
    {
        $action = Action::make('breakGlassToLive')
            ->label('Break-glass to live')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->visible(function (): bool {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $user = auth()->user();

                return ! $record->conformanceState()->isLive()
                    && $user instanceof User
                    && $user->can(Permissions::IntegrationsBreakGlass)
                    && $user->can('update', $record);
            })
            ->form([
                Textarea::make('reason')
                    ->label('Break-glass reason')
                    ->required()
                    ->rows(3)
                    ->helperText('Audited justification for skipping the conformance ladder.'),
            ])
            ->action(function (array $data): void {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                $this->authorize('update', $record);

                try {
                    app(PromoteOutboundConnectionConformance::class)->breakGlassToLive(
                        $record,
                        $user,
                        (string) ($data['reason'] ?? ''),
                    );
                    $this->refreshFormData(['conformance_state']);
                    Notification::make()
                        ->title('Break-glass applied')
                        ->body('Connection is now live.')
                        ->warning()
                        ->send();
                } catch (AuthorizationException|InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Break-glass denied')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });

        return RegulatoryCompliance::apply(
            $action,
            'outbound_connection_break_glass',
            requireReason: true,
            existingReasonField: 'reason',
        );
    }
}

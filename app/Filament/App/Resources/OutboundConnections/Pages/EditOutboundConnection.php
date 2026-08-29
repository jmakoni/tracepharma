<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Actions\Integrations\PromoteOutboundConnectionConformance;
use App\Filament\App\Concerns\TransformsConnectionCredentials;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\OutboundConnection;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Integrations\OutboundConnectionDefaultSync;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

class EditOutboundConnection extends EditRecord
{
    use TransformsConnectionCredentials;

    protected static string $resource = OutboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->promoteAction(),
            $this->breakGlassAction(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fillDedicatedCredentialFields($data, $this->record->credentials ?? []);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['conformance_state']);

        $existingSettings = $this->record->settings ?? [];
        $incomingSettings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $data['settings'] = array_merge($existingSettings, $incomingSettings);

        return $this->transformOutboundCredentialPairs($data, $this->record->credentials ?? []);
    }

    protected function afterSave(): void
    {
        OutboundConnectionDefaultSync::ensureSingleDefault($this->record->fresh());
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

        return RegulatoryCompliance::apply($action, requiresReason: false);
    }
}

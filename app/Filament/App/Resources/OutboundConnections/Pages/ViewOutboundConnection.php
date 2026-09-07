<?php

namespace App\Filament\App\Resources\OutboundConnections\Pages;

use App\Actions\Integrations\PromoteOutboundConnectionConformance;
use App\Enums\OutboundTransport;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\ConnectionGoLiveChecklist;
use App\Models\OutboundConnection;
use App\Models\User;
use App\Services\Epcis\Outbound\HttpsOutboundSender;
use App\Support\Auth\Permissions;
use App\Support\Integrations\GoLiveChecklistEvaluator;
use App\Support\Integrations\GoLiveEvidencePack;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ViewOutboundConnection extends ViewRecord
{
    protected static string $resource = OutboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->probeConnectivityAction(),
            $this->goLiveChecklistAction(),
            $this->signOffGoLiveAction(),
            $this->downloadEvidencePackAction(),
            $this->promoteAction(),
            $this->breakGlassAction(),
            EditAction::make(),
        ];
    }

    private function goLiveChecklistAction(): Action
    {
        return Action::make('goLiveChecklist')
            ->label('Go-live checklist')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->modalHeading('Go-live checklist')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (): HtmlString {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $steps = app(GoLiveChecklistEvaluator::class)->evaluate($record);

                $items = collect($steps)->map(static function (array $step): string {
                    $icon = $step['done'] ? '✅' : '⬜';

                    return '<li>'.$icon.' <strong>'.e($step['label']).'</strong>'
                        .' <span class="text-gray-500">('.e($step['source']).')</span>'
                        .'<br><span class="text-sm text-gray-600">'.e($step['detail']).'</span></li>';
                })->implode('');

                return new HtmlString('<ul class="space-y-2">'.$items.'</ul>');
            });
    }

    private function signOffGoLiveAction(): Action
    {
        return Action::make('signOffGoLive')
            ->label('Sign off go-live')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(function (): bool {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $checklist = ConnectionGoLiveChecklist::forConnection(
                    ConnectionGoLiveChecklist::TYPE_OUTBOUND,
                    (int) $record->getKey(),
                );

                return ! $checklist->isSignedOff()
                    && auth()->user()?->can('update', $record) === true;
            })
            ->requiresConfirmation()
            ->modalHeading('Sign off go-live')
            ->modalDescription('Confirms you have reviewed the checklist and evidence for this connection. Required before promotion to live.')
            ->action(function (): void {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $this->authorize('update', $record);

                ConnectionGoLiveChecklist::forConnection(
                    ConnectionGoLiveChecklist::TYPE_OUTBOUND,
                    (int) $record->getKey(),
                )->signOff($user);

                Notification::make()
                    ->title('Go-live signed off')
                    ->success()
                    ->send();
            });
    }

    private function downloadEvidencePackAction(): Action
    {
        return Action::make('downloadEvidencePack')
            ->label('Evidence pack')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (): StreamedResponse {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $tenant = tenant();

                $markdown = app(GoLiveEvidencePack::class)->render($tenant, $record);

                return response()->streamDownload(
                    static function () use ($markdown): void {
                        echo $markdown;
                    },
                    'go-live-evidence-connection-'.(int) $record->getKey().'.md',
                    ['Content-Type' => 'text/markdown'],
                );
            });
    }

    private function probeConnectivityAction(): Action
    {
        return Action::make('probeConnectivity')
            ->label('Test connectivity')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->visible(function (): bool {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();

                return $record->transport === OutboundTransport::Https
                    && auth()->user()?->can('update', $record) === true;
            })
            ->action(function (): void {
                /** @var OutboundConnection $record */
                $record = $this->getRecord();
                $this->authorize('update', $record);

                $result = app(HttpsOutboundSender::class)->probe($record);

                $settings = (array) ($record->settings ?? []);
                $settings['last_probe_ok'] = $result['ok'];
                $settings['last_probe_at'] = now()->toIso8601String();
                $record->forceFill(['settings' => $settings])->save();

                if ($result['ok']) {
                    Notification::make()
                        ->title('Connectivity OK')
                        ->body($result['message'])
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Connectivity failed')
                    ->body($result['message'])
                    ->danger()
                    ->send();
            });
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

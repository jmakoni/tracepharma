<?php

namespace App\Filament\App\Resources\InboundConnections\Pages;

use App\Enums\InboundTransport;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Models\ConnectionGoLiveChecklist;
use App\Models\InboundConnection;
use App\Models\User;
use App\Support\Integrations\GoLiveChecklistEvaluator;
use App\Support\Integrations\GoLiveEvidencePack;
use App\Support\SftpConnectionProviderFactory;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ViewInboundConnection extends ViewRecord
{
    protected static string $resource = InboundConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->testConnectivityAction(),
            $this->regenerateTokenAction(),
            $this->goLiveChecklistAction(),
            $this->signOffGoLiveAction(),
            $this->downloadEvidencePackAction(),
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
            ->modalContent(function (InboundConnection $record): HtmlString {
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
            ->visible(function (InboundConnection $record): bool {
                $checklist = ConnectionGoLiveChecklist::forConnection(
                    ConnectionGoLiveChecklist::TYPE_INBOUND,
                    (int) $record->getKey(),
                );

                return ! $checklist->isSignedOff()
                    && auth()->user()?->can('update', $record) === true;
            })
            ->requiresConfirmation()
            ->modalHeading('Sign off go-live')
            ->modalDescription('Confirms you have reviewed the checklist and evidence for this connection.')
            ->action(function (InboundConnection $record): void {
                $user = auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                $this->authorize('update', $record);

                ConnectionGoLiveChecklist::forConnection(
                    ConnectionGoLiveChecklist::TYPE_INBOUND,
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
            ->action(function (InboundConnection $record): StreamedResponse {
                $markdown = app(GoLiveEvidencePack::class)->render(tenant(), $record);

                return response()->streamDownload(
                    static function () use ($markdown): void {
                        echo $markdown;
                    },
                    'go-live-evidence-inbound-'.(int) $record->getKey().'.md',
                    ['Content-Type' => 'text/markdown'],
                );
            });
    }

    private function testConnectivityAction(): Action
    {
        return Action::make('testConnectivity')
            ->label('Test connectivity')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(function (InboundConnection $record): void {
                if ($record->transport === InboundTransport::Sftp) {
                    $this->probeSftp($record);

                    return;
                }

                // HTTPS/AS2 inbound is receive-only: there is nothing to dial.
                // Report configuration readiness instead of a network probe.
                $checks = [];
                $checks[] = [$record->isApproved(), 'Platform review: '.$record->approval_status->label()];
                $checks[] = [(bool) $record->is_active, $record->is_active ? 'Connection is active.' : 'Connection is inactive.'];
                $checks[] = [
                    filled($record->inbound_token),
                    filled($record->inbound_token) ? 'Webhook token is set.' : 'Webhook token is missing.',
                ];

                $failed = collect($checks)->firstWhere(0, false);

                Notification::make()
                    ->title($failed === null ? 'Configuration ready' : 'Configuration incomplete')
                    ->body(collect($checks)->map(fn (array $c): string => ($c[0] ? '✓' : '✗').' '.$c[1])->implode("\n"))
                    ->color($failed === null ? 'success' : 'danger')
                    ->persistent()
                    ->send();
            });
    }

    private function probeSftp(InboundConnection $record): void
    {
        try {
            $provider = SftpConnectionProviderFactory::forInboundConnection($record);
            $connection = $provider->provide();
            $connection->close();

            Notification::make()
                ->title('SFTP connection OK')
                ->body('Authenticated and connected successfully.')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('SFTP connection failed')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function regenerateTokenAction(): Action
    {
        return Action::make('regenerateToken')
            ->label('Regenerate token')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (InboundConnection $record): bool => $record->transport === InboundTransport::Https)
            ->requiresConfirmation()
            ->modalHeading('Regenerate inbound token')
            ->modalDescription('The current token stops working immediately. Copy the new token from the next screen and share it with the sender.')
            ->action(function (InboundConnection $record): void {
                $token = (string) Str::uuid();
                $record->forceFill(['inbound_token' => $token])->save();

                activity()
                    ->performedOn($record)
                    ->causedBy(auth()->user())
                    ->log('inbound token regenerated');

                Notification::make()
                    ->title('New inbound token (shown once)')
                    ->body($token)
                    ->warning()
                    ->persistent()
                    ->send();
            });
    }
}

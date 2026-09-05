<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\App\Resources\OutboundConnections\OutboundConnectionResource;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Models\InboundConnection;
use App\Models\OutboundConnection;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Integrations\IntegrationHealthMetrics;
use App\Support\Integrations\OutboundTransportAvailability;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Throwable;
use UnitEnum;

class IntegrationHealth extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Integration health';

    protected static ?string $title = 'Integration health';

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected string $view = 'filament.app.pages.integration-health';

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        return ($features->supportsInboundIntegrations() || $features->supportsOutboundIntegrations())
            && JobRoleAccess::allows(Permissions::NavIntegrations);
    }

    public function supportsInbound(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsInboundIntegrations();
    }

    public function supportsOutbound(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsOutboundIntegrations();
    }

    /**
     * @return array{success: int, error: int, in_flight: int, total: int}
     */
    public function inboundStats(): array
    {
        $counts = app(IntegrationHealthMetrics::class)->inboundStatusCountsLast24h(auth()->user());

        $success = ($counts['parsed'] ?? 0) + ($counts['validated'] ?? 0);
        $error = $counts['error'] ?? 0;
        $inFlight = ($counts['received'] ?? 0) + ($counts['parsing'] ?? 0);
        $total = array_sum($counts);

        return [
            'success' => $success,
            'error' => $error,
            'in_flight' => $inFlight,
            'total' => $total,
        ];
    }

    /**
     * @return array{sent: int, failed: int, queued: int, skipped: int, total: int}
     */
    public function outboundStats(): array
    {
        $counts = app(IntegrationHealthMetrics::class)->outboundTransmissionCountsLast24h(auth()->user());

        $sent = $counts['sent'] ?? 0;
        $failed = $counts['failed'] ?? 0;
        $queued = ($counts['queued'] ?? 0) + ($counts['sending'] ?? 0) + ($counts['pending'] ?? 0);
        $skipped = $counts['skipped'] ?? 0;
        $total = array_sum($counts);

        return [
            'sent' => $sent,
            'failed' => $failed,
            'queued' => $queued,
            'skipped' => $skipped,
            'total' => $total,
        ];
    }

    /**
     * @return Collection<int, InboundConnection>
     */
    public function inboundConnections(): Collection
    {
        return app(IntegrationHealthMetrics::class)->inboundConnections();
    }

    /**
     * @return Collection<int, OutboundConnection>
     */
    public function outboundConnections(): Collection
    {
        return app(IntegrationHealthMetrics::class)->outboundConnections();
    }

    public function inboundConnectionsIndexUrl(): ?string
    {
        return $this->resourceIndexUrl(InboundConnectionResource::class);
    }

    public function outboundConnectionsIndexUrl(): ?string
    {
        return $this->resourceIndexUrl(OutboundConnectionResource::class);
    }

    public function inboundEpcisIndexUrl(): ?string
    {
        return $this->resourceIndexUrl(EpcisDocumentResource::class);
    }

    public function outboundEpcisIndexUrl(): ?string
    {
        return $this->resourceIndexUrl(OutboundEpcisDocumentResource::class);
    }

    public function inboundConnectionViewUrl(InboundConnection $connection): ?string
    {
        return $this->resourceViewUrl(InboundConnectionResource::class, $connection);
    }

    public function outboundConnectionViewUrl(OutboundConnection $connection): ?string
    {
        return $this->resourceViewUrl(OutboundConnectionResource::class, $connection);
    }

    public function redactLastError(?string $error): ?string
    {
        return app(IntegrationHealthMetrics::class)->redactLastError($error);
    }

    public function hasActiveLegacySftpOutbound(): bool
    {
        return $this->supportsOutbound()
            && app(IntegrationHealthMetrics::class)->hasActiveLegacySftpOutbound();
    }

    public function activeLegacySftpOutboundCount(): int
    {
        return app(IntegrationHealthMetrics::class)->activeLegacySftpOutboundCount();
    }

    public function canDeactivateLegacySftpOutbound(): bool
    {
        if (! $this->hasActiveLegacySftpOutbound()) {
            return false;
        }

        return OutboundConnectionResource::canCreate();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        if (! $this->canDeactivateLegacySftpOutbound()) {
            return [];
        }

        $count = $this->activeLegacySftpOutboundCount();

        return [
            Action::make('deactivateLegacySftp')
                ->label('Deactivate SFTP outbound')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->canDeactivateLegacySftpOutbound())
                ->modalHeading('Deactivate SFTP outbound connections?')
                ->modalDescription(
                    'This will deactivate '.$count.' active SFTP outbound connection(s). '
                    .'Use when cleaning up unused SFTP endpoints.',
                )
                ->modalSubmitActionLabel('Deactivate all')
                ->action(function (): void {
                    abort_unless($this->canDeactivateLegacySftpOutbound(), 403);

                    $deactivated = OutboundTransportAvailability::deactivateActiveLegacySftpConnections();

                    Notification::make()
                        ->title("Deactivated {$deactivated} SFTP outbound connection(s)")
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function resourceIndexUrl(string $resource): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            $panel = Filament::getPanel('app');
            $name = $resource::getRouteBaseName($panel).'.index';

            if (! Route::has($name)) {
                return null;
            }

            return $resource::getUrl('index', panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function resourceViewUrl(string $resource, InboundConnection|OutboundConnection $record): ?string
    {
        try {
            if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $record], panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    public static function getDocumentation(): array|string
    {
        return 'integrations.integration-health';
    }
}

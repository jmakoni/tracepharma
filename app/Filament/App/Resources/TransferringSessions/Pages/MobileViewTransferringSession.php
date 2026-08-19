<?php

namespace App\Filament\App\Resources\TransferringSessions\Pages;

use App\Filament\App\Resources\TransferringSessions\Concerns\InteractsWithTransferringSessionHud;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Support\Auth\SiteAccess;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Scan-only floor transfer (phone/tablet). Desktop HUD remains {@see ViewTransferringSession}.
 */
class MobileViewTransferringSession extends ViewRecord
{
    use InteractsWithTransferringSessionHud {
        getHeaderActions as getTransferringSessionHudHeaderActions;
    }

    protected static string $resource = TransferringSessionResource::class;

    protected string $view = 'filament.app.resources.transferring-sessions.pages.mobile-view-transferring-session';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-transfer-page',
    ];

    public function content(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_values(array_filter(
            $this->getTransferringSessionHudHeaderActions(),
            function (Action $action): bool {
                if ($action->getName() !== 'completeTransfer') {
                    return false;
                }

                $user = auth()->user();

                return $user !== null
                    && $this->isOpen()
                    && SiteAccess::canAccessSite($user, (int) $this->getRecord()->from_site_id);
            },
        ));
    }

    public function routeDisplayLabel(): string
    {
        /** @var TransferringSession $record */
        $record = $this->getRecord();

        $from = $record->fromSite?->name;
        $to = $record->toSite?->name;

        if (filled($from) && filled($to)) {
            return $from.' → '.$to;
        }

        return 'Transfer #'.$record->getKey();
    }

    public function transferListUrl(): string
    {
        return TransferringSessionResource::getUrl(name: 'index', panel: 'app');
    }

    public function cartBadgeCount(): int
    {
        return $this->confirmedCount();
    }

    public function recentScansCaption(): ?string
    {
        $total = $this->confirmedCount();

        if ($total <= 8) {
            return null;
        }

        return 'Showing last 8 of '.$total;
    }

    public function shipDisabledReason(): ?string
    {
        if (! $this->isOpen()) {
            return null;
        }

        if ($this->confirmedCount() > 0) {
            return null;
        }

        return 'Scan at least one item to ship.';
    }

    public function canShipTransfer(): bool
    {
        return $this->isOpen() && $this->confirmedCount() > 0;
    }

    /**
     * @return Collection<int, TransferringScanLine>
     */
    public function recentScanLines(): Collection
    {
        return TransferringScanLine::query()
            ->where('transferring_session_id', $this->getRecord()->getKey())
            ->where('status', 'confirmed')
            ->select([
                'id',
                'transferring_session_id',
                'epc_id',
                'status',
                'scan_raw',
                'confirmed_at',
            ])
            ->with([
                'epc:id,epc_uri,sscc18,gtin14,serial_number',
            ])
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    public function recentScanLineLabel(TransferringScanLine $line): string
    {
        $epc = $line->epc;

        if ($epc !== null) {
            if (filled($epc->sscc18)) {
                return (string) $epc->sscc18;
            }

            if (filled($epc->gtin14)) {
                return (string) $epc->gtin14.(filled($epc->serial_number) ? ' / '.$epc->serial_number : '');
            }

            if (filled($epc->epc_uri)) {
                return (string) $epc->epc_uri;
            }
        }

        return filled($line->scan_raw) ? (string) $line->scan_raw : 'Scan #'.$line->getKey();
    }
}

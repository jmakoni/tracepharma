<?php

namespace App\Filament\App\Resources\TransferringSessions\Pages;

use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Receiving\ReceivingSession;
use App\Models\Transferring\TransferringSession;
use App\Support\Auth\CurrentSite;
use App\Support\Receiving\ReceiveLayout;
use App\Support\Transferring\TransferLayout;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

class MobileListTransferringSessions extends Page
{
    protected static string $resource = TransferringSessionResource::class;

    protected string $view = 'filament.app.resources.transferring-sessions.pages.mobile-list-transferring-sessions';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-shell-page tp-floor-list-page tp-floor-transfer-list-page',
    ];

    /**
     * `ship` = origin scans; `receive` = in-transit arrivals at this site.
     */
    public function listMode(): string
    {
        return 'ship';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function listHeading(): string
    {
        return $this->listMode() === 'receive' ? 'Transfer receive' : 'Transfer';
    }

    public function showsCreateTransfer(): bool
    {
        return $this->listMode() === 'ship'
            && TransferringSessionResource::canCreate();
    }

    /**
     * @return Collection<int, TransferringSession>
     */
    public function sessions(): Collection
    {
        $query = TransferringSessionResource::getEloquentQuery()
            ->with(['fromSite', 'toSite', 'receivingSession'])
            ->orderByDesc('id')
            ->limit(50);

        $siteId = CurrentSite::id();

        if ($this->listMode() === 'receive') {
            $query->where('status', 'in_transit');

            if ($siteId !== null) {
                $query->where('to_site_id', $siteId);
            }

            return $query->get();
        }

        $query->whereIn('status', ['open', 'in_progress']);

        if ($siteId !== null) {
            $query->where('from_site_id', $siteId);
        }

        return $query->get();
    }

    public function floorSessionUrl(TransferringSession $session): string
    {
        if ($this->listMode() === 'receive') {
            $receiving = $session->receivingSession;

            if (
                $receiving instanceof ReceivingSession
                && in_array($receiving->status, ['open', 'in_progress'], true)
                && ReceivingSessionResource::canView($receiving)
            ) {
                return ReceiveLayout::floorUrl($receiving);
            }
        }

        return TransferLayout::floorUrl($session);
    }

    public function desktopListUrl(): string
    {
        return TransferringSessionResource::getUrl('index', panel: 'app');
    }

    public function floorListUrl(): string
    {
        return $this->listMode() === 'receive'
            ? TransferringSessionResource::getUrl('receive-floor', panel: 'app')
            : TransferringSessionResource::getUrl('list-floor', panel: 'app');
    }

    public function createUrl(): string
    {
        return TransferringSessionResource::getUrl('create', panel: 'app');
    }

    public function emptyListMessage(): string
    {
        return $this->listMode() === 'receive'
            ? 'No in-transit transfers awaiting receive at this site.'
            : 'No open transferring sessions.';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

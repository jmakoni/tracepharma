<?php

namespace App\Filament\App\Resources\ReceivingSessions\Pages;

use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ReceivingSessionKind;
use App\Filament\App\Resources\EpcisDocuments\Actions\StartReceivingAction;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\Notifications\Notification;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleEpcisReceiveDocuments;
use App\Support\Receiving\ReceiveLayout;
use DomainException;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class MobileListReceivingSessions extends Page
{
    protected static string $resource = ReceivingSessionResource::class;

    protected string $view = 'filament.app.resources.receiving-sessions.pages.mobile-list-receiving-sessions';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-shell-page tp-floor-list-page tp-floor-receive-list-page',
    ];

    /**
     * `epcis` = inbound files ready to receive; `scan-first` = open scan-first sessions.
     */
    public function listMode(): string
    {
        return 'epcis';
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
        return $this->listMode() === 'scan-first' ? 'Scan first' : 'EPCIS Receive';
    }

    /**
     * @return Collection<int, EpcisDocument>
     */
    public function documents(): Collection
    {
        return app(EligibleEpcisReceiveDocuments::class)->list();
    }

    public function documentRowTitle(EpcisDocument $document): string
    {
        return app(EligibleEpcisReceiveDocuments::class)->rowTitle($document);
    }

    public function documentRowMeta(EpcisDocument $document): string
    {
        return app(EligibleEpcisReceiveDocuments::class)->rowMeta($document);
    }

    /**
     * @return Collection<int, ReceivingSession>
     */
    public function sessions(): Collection
    {
        return ReceivingSessionResource::getEloquentQuery()
            ->with(['site'])
            ->whereIn('status', ['open', 'in_progress'])
            ->where('session_kind', ReceivingSessionKind::ScanFirst)
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function floorSessionUrl(ReceivingSession $session): string
    {
        return ReceiveLayout::floorUrl($session);
    }

    public function desktopListUrl(): string
    {
        return ReceivingSessionResource::getUrl('index', panel: 'app');
    }

    public function floorListUrl(): string
    {
        return $this->listMode() === 'scan-first'
            ? ReceivingSessionResource::getUrl('scan-first-floor', panel: 'app')
            : ReceivingSessionResource::getUrl('list-floor', panel: 'app');
    }

    public function createUrl(): string
    {
        return ReceivingSessionResource::getUrl('create', panel: 'app');
    }

    public function openDocument(int $documentId): void
    {
        if ($this->listMode() !== 'epcis') {
            return;
        }

        $document = app(EligibleEpcisReceiveDocuments::class)->find($documentId);

        if ($document === null) {
            Notification::make()
                ->title('Cannot start receiving')
                ->body('This file is no longer ready to receive.')
                ->danger()
                ->ephemeral()
                ->send();

            return;
        }

        $siteId = CurrentSite::id();

        try {
            $session = StartReceivingAction::open(
                $document,
                $siteId !== null ? ['site_id' => $siteId] : [],
                auth()->id() !== null ? (int) auth()->id() : null,
            );
        } catch (InvalidArgumentException|DomainException $e) {
            Notification::make()
                ->title('Cannot start receiving')
                ->body($e->getMessage())
                ->danger()
                ->ephemeral()
                ->send();

            return;
        }

        $this->redirect(ReceiveLayout::floorUrl($session));
    }

    public function startScanFirst(): void
    {
        if ($this->listMode() !== 'scan-first') {
            return;
        }

        try {
            $session = app(OpenScanFirstReceivingSession::class)->handle(
                openedBy: auth()->id(),
            );
        } catch (InvalidArgumentException|DomainException $e) {
            Notification::make()
                ->title('Could not open scan-first')
                ->body($e->getMessage())
                ->danger()
                ->ephemeral()
                ->send();

            return;
        }

        $this->redirect(ReceiveLayout::floorUrl($session));
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}

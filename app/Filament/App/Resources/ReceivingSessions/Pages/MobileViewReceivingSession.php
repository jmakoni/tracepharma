<?php

namespace App\Filament\App\Resources\ReceivingSessions\Pages;

use App\Filament\App\Resources\ReceivingSessions\Concerns\InteractsWithReceivingSessionHud;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Scan-only floor receive (phone/tablet). Desktop HUD remains {@see ViewReceivingSession}.
 */
class MobileViewReceivingSession extends ViewRecord
{
    use InteractsWithReceivingSessionHud {
        getHeaderActions as getReceivingSessionHudHeaderActions;
    }

    protected static string $resource = ReceivingSessionResource::class;

    protected string $view = 'filament.app.resources.receiving-sessions.pages.mobile-view-receiving-session';

    /**
     * @var array<string, mixed>
     */
    protected array $extraBodyAttributes = [
        'class' => 'tp-floor-receive-page',
    ];

    /**
     * Floor chrome only — no dense relation tables / infolist.
     */
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
     * Register floor mountable actions. Header chrome is CSS-hidden on this page;
     * do not force visible(false) — Filament treats hidden actions as disabled and
     * mountAction() silently no-ops. parent::getHeaderActions() is Filament's empty
     * default, so alias the HUD trait method instead.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_values(array_filter(
            $this->getReceivingSessionHudHeaderActions(),
            fn (Action $action): bool => in_array($action->getName(), [
                'completeReceiving',
                'closeTransferWithShortage',
                'retryReceiveEpcis',
                'resetScans',
                'unpackHierarchy',
                'cancelReceiving',
            ], true),
        ));
    }

    public function siteDisplayName(): string
    {
        /** @var ReceivingSession $record */
        $record = $this->getRecord();

        return filled($record->site?->name)
            ? (string) $record->site->name
            : (string) (tenant()?->name ?? 'Receive site');
    }

    public function receiveListUrl(): string
    {
        return ReceivingSessionResource::getUrl(name: 'index', panel: 'app');
    }

    /**
     * FAB badge — count of confirmed/unexpected scan lines (matches sheet list semantics).
     */
    public function cartBadgeCount(): int
    {
        return $this->scannedLineTotalCount();
    }

    /**
     * Total confirmed/unexpected lines for this session (for "last 8 of N").
     */
    public function scannedLineTotalCount(): int
    {
        return (int) ReceivingScanLine::query()
            ->where('receiving_session_id', $this->getRecord()->getKey())
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->count();
    }

    public function recentScansCaption(): ?string
    {
        $total = $this->scannedLineTotalCount();

        if ($total <= 8) {
            return null;
        }

        return 'Showing last 8 of '.$total;
    }

    public function completeDisabledReason(): ?string
    {
        if ($this->isCompleted() || $this->canCompleteManually()) {
            return null;
        }

        if (! $this->isScanFirst()) {
            return 'This receive finishes automatically — keep scanning.';
        }

        return 'Scan at least one item to complete.';
    }

    /**
     * Last 8 confirmed/unexpected lines for the cart sheet.
     *
     * @return Collection<int, ReceivingScanLine>
     */
    public function recentScanLines(): Collection
    {
        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->getRecord()->getKey())
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->select([
                'id',
                'receiving_session_id',
                'epc_id',
                'line_role',
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

    protected function usesStagedScans(): bool
    {
        return true;
    }

    public function recentScanLineLabel(ReceivingScanLine $line): string
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

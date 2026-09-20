<?php

namespace App\Filament\App\Resources\ReceivingSessions\Pages;

use App\Actions\Receiving\UnconfirmReceivingScanLine;
use App\Filament\App\Concerns\SetsFloorCameraScanPace;
use App\Filament\App\Resources\ReceivingSessions\Concerns\InteractsWithReceivingSessionHud;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\Notifications\Notification;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Support\Gs1\ElementString;
use App\Support\Receiving\ReceivingScanLevel;
use DomainException;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Scan-only floor receive (phone/tablet). Each scan confirms immediately — same
 * commit path as desktop {@see ViewReceivingSession}; "Just scanned" lists recent
 * confirmed lines with remove.
 */
class MobileViewReceivingSession extends ViewRecord
{
    use InteractsWithReceivingSessionHud {
        getHeaderActions as getReceivingSessionHudHeaderActions;
    }
    use SetsFloorCameraScanPace;

    protected static string $resource = ReceivingSessionResource::class;

    protected string $view = 'filament.app.resources.receiving-sessions.pages.mobile-view-receiving-session';

    private const RECENT_SCAN_LIMIT = 8;

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
                'closeOpenTote',
                'acceptRemaining',
                'completeReceiving',
                'closeTransferWithShortage',
                'retryReceiveEpcis',
                'resetScans',
                'unpackHierarchy',
                'cancelReceiving',
                'deleteReceiving',
                'attachInvoice',
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

    public function floorScanPlaceholder(): string
    {
        $policy = $this->receivingPolicy();

        if ($policy->operatorScansUnitsOnly()) {
            return 'Scan the unit 2D code';
        }

        if ($policy->operatorScansCaseOnly()) {
            return 'Scan the case';
        }

        if ($policy->operatorScansSsccOnly()) {
            return 'Scan pallet SSCC';
        }

        return match ($policy->preferredScanLevel()) {
            ReceivingScanLevel::Case => 'Scan case SSCC',
            ReceivingScanLevel::ToteOrCase => 'Scan SSCC or case',
            default => 'Scan pallet SSCC',
        };
    }

    public function recentScansCaption(): ?string
    {
        $total = $this->recentConfirmedScanLineCount();

        if ($total <= self::RECENT_SCAN_LIMIT) {
            return null;
        }

        return 'Showing last '.self::RECENT_SCAN_LIMIT.' of '.$total;
    }

    /**
     * @return list<array{id: int, label: string, type: string, can_remove: bool}>
     */
    public function recentConfirmedScanRows(): array
    {
        return $this->recentConfirmedScanLines()
            ->map(fn (ReceivingScanLine $line): array => [
                'id' => (int) $line->getKey(),
                'label' => $this->recentScanLineLabel($line),
                'type' => $this->recentScanLineTypeLabel($line),
                'can_remove' => $this->canRemoveRecentScanLine($line),
            ])
            ->values()
            ->all();
    }

    public function latestUndoableScanLine(): ?ReceivingScanLine
    {
        if ($this->isCompleted()) {
            return null;
        }

        $line = $this->recentConfirmedScanLines()->first();

        if ($line === null || ! $this->canRemoveRecentScanLine($line)) {
            return null;
        }

        return $line;
    }

    public function undoLastScan(): void
    {
        $line = $this->latestUndoableScanLine();

        if ($line === null) {
            Notification::make()
                ->title('Nothing to undo')
                ->warning()
                ->ephemeral()->send();

            $this->dispatch('focus-scan');

            return;
        }

        $this->removeRecentScanLine((int) $line->getKey());
        $this->dispatch('focus-scan');
    }

    public function canRemoveRecentScanLine(ReceivingScanLine $line): bool
    {
        /** @var ReceivingSession $session */
        $session = $this->getRecord();

        if ($session->status === 'completed' || $session->receiving_events_generated_at !== null) {
            return false;
        }

        if (! in_array($session->status, ['open', 'in_progress'], true)) {
            return false;
        }

        return in_array($line->status, ['confirmed', 'unexpected'], true);
    }

    public function removeRecentScanLine(int $lineId): void
    {
        /** @var ReceivingSession $session */
        $session = $this->getRecord();

        $line = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->whereKey($lineId)
            ->first();

        if ($line === null || ! $this->canRemoveRecentScanLine($line)) {
            Notification::make()
                ->title('Remove blocked')
                ->body('This scan cannot be removed.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(UnconfirmReceivingScanLine::class)->handle($line, auth()->id());
        } catch (DomainException $e) {
            Notification::make()
                ->title('Remove blocked')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->getRecord()->refresh()->loadMissing([
            'document',
            'document.inboundShipment',
            'inboundShipment',
            'tradingPartner',
            'site',
            'matchedDocument',
            'transferringSession',
            'activeParentEpc',
        ]);
        $this->dispatch('receiving-session-hud-refresh');

        Notification::make()
            ->title('Scan removed')
            ->success()
            ->send();

        $this->dispatch('focus-scan');
    }

    /**
     * @return Collection<int, ReceivingScanLine>
     */
    private function recentConfirmedScanLines(): Collection
    {
        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->getRecord()->getKey())
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->whereNotNull('scan_raw')
            ->where('scan_raw', '!=', '')
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
                'epc:id,epc_uri,sscc18,gtin14,serial_number,epc_type,ai_01_21,ai_00',
            ])
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_SCAN_LIMIT)
            ->get();
    }

    private function recentConfirmedScanLineCount(): int
    {
        return (int) ReceivingScanLine::query()
            ->where('receiving_session_id', $this->getRecord()->getKey())
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->whereNotNull('scan_raw')
            ->where('scan_raw', '!=', '')
            ->count();
    }

    private function recentScanLineLabel(ReceivingScanLine $line): string
    {
        $raw = trim((string) ($line->scan_raw ?? ''));

        if ($raw !== '') {
            return ElementString::identityBarcodeDisplay($raw);
        }

        $epc = $line->epc;

        if ($epc !== null) {
            if (filled($epc->ai_01_21)) {
                return ElementString::identityBarcodeDisplay((string) $epc->ai_01_21);
            }

            if (filled($epc->sscc18)) {
                return ElementString::identityBarcodeDisplay((string) $epc->sscc18);
            }

            if (filled($epc->ai_00)) {
                return ElementString::identityBarcodeDisplay((string) $epc->ai_00);
            }

            if (filled($epc->gtin14) && filled($epc->serial_number)) {
                return ElementString::identityBarcodeDisplay(
                    ElementString::encodeSgtin((string) $epc->gtin14, (string) $epc->serial_number),
                );
            }

            if (filled($epc->epc_uri)) {
                return ElementString::identityBarcodeDisplay((string) $epc->epc_uri);
            }
        }

        return 'Scan #'.$line->getKey();
    }

    private function recentScanLineTypeLabel(ReceivingScanLine $line): string
    {
        return $line->line_role === 'parent'
            ? $this->parentTypeLabel()
            : $this->childTypeLabel();
    }
}

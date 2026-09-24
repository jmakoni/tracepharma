<?php

namespace App\Filament\App\Pages\Concerns;

use App\Actions\Packing\CompletePackingSession;
use App\Actions\Packing\DeletePackingSession;
use App\Actions\Packing\OpenPackingSession;
use App\Actions\Packing\StagePackingScan;
use App\Actions\Packing\UnstagePackingScanLine;
use App\Enums\PackingSessionKind;
use App\Models\Epcis\Epc;
use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use App\Support\Auth\CurrentSite;
use App\Support\Floor\EpcExclusiveBlock;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use App\Support\Floor\ResolveOpenFloorSessionUrl;
use App\Support\Tracing\Gs1DualDisplay;
use Livewire\Attributes\Locked;

trait InteractsWithPackingWorkstationSession
{
    #[Locked]
    public ?int $packingSessionId = null;

    abstract protected function packingSessionKind(): PackingSessionKind;

    public function mountInteractsWithPackingWorkstationSession(): void
    {
        $sessionId = request()->query('session');
        if (filled($sessionId)) {
            $this->packingSessionId = (int) $sessionId;
            $this->hydratePackingChildrenFromDatabase();
            $this->hydratePackingSessionParentFromDatabase();
        }
    }

    protected function packingSession(): ?PackingSession
    {
        if ($this->packingSessionId === null) {
            return null;
        }

        return PackingSession::query()->find($this->packingSessionId);
    }

    protected function ensurePackingSession(int $siteId): PackingSession
    {
        $session = app(OpenPackingSession::class)->handle(
            $this->packingSessionKind(),
            $siteId,
            auth()->id(),
            $this->packingSessionId,
        );

        $this->packingSessionId = (int) $session->getKey();
        $this->syncPackingSessionQueryString();

        return $session;
    }

    protected function packingExclusiveContext(): ExclusiveSessionContext
    {
        return ExclusiveSessionContext::forOptionalPacking($this->packingSession());
    }

    protected function refuseIfEpcReserved(Epc $epc, string $scan, string $tone = 'warn'): bool
    {
        $block = app(EpcExclusiveSessionGate::class)->checkScannedEpc($epc, $this->packingExclusiveContext());
        if ($block === null) {
            return false;
        }

        $this->flashExclusiveBlock($block, $scan, $tone);

        return true;
    }

    protected function flashExclusiveBlock(EpcExclusiveBlock $block, string $scan, string $tone = 'warn'): void
    {
        $url = app(ResolveOpenFloorSessionUrl::class)->urlFromBlock($block, ['scan' => $scan]);
        $this->flash($tone, $block->messageWithOpenHint($url));
        $this->scan = '';
        $this->dispatch('focus-scan');
        $this->dispatch('scan-result', tone: $tone === 'error' ? 'error' : 'warn');
    }

    protected function reservePackingParentScan(PackingSession $session, Epc $parent): bool
    {
        $result = app(StagePackingScan::class)->handle(
            $session,
            (string) $parent->epc_uri,
            'parent',
            auth()->id(),
        );

        if ($result['ok'] || ($result['effect'] ?? '') === 'already_staged') {
            return true;
        }

        $block = EpcExclusiveBlock::fromScanResult($result);
        if ($block !== null) {
            $this->flashExclusiveBlock($block, (string) $parent->epc_uri, 'error');
        } else {
            $this->flash('error', (string) $result['message']);
        }

        return false;
    }

    protected function syncPackingSessionQueryString(): void
    {
        if ($this->packingSessionId === null || ! method_exists(static::class, 'getUrl')) {
            return;
        }

        $url = static::getUrl(['session' => $this->packingSessionId], panel: 'app');
        $this->js('history.replaceState(history.state, "", '.json_encode($url).')');
    }

    protected function hydratePackingSessionParentFromDatabase(): void
    {
        $session = $this->packingSession();
        if ($session === null) {
            return;
        }

        if ($session->parent_label_id !== null) {
            $this->parentLabelId = (int) $session->parent_label_id;
        }

        if ($session->parent_sscc18 !== null) {
            $this->parentSscc18 = $session->parent_sscc18;
        }

        if ($session->parent_epc_id !== null && property_exists($this, 'parentEpcId')) {
            $this->parentEpcId = (int) $session->parent_epc_id;
        }
    }

    /**
     * @return list<array{epc_id: int, label: string}>
     */
    protected function hydratePackingChildrenFromDatabase(): array
    {
        $session = $this->packingSession();
        if ($session === null) {
            return [];
        }

        if (! property_exists($this, 'children')) {
            return [];
        }

        $lines = PackingScanLine::query()
            ->where('packing_session_id', $session->getKey())
            ->where('status', 'staged')
            ->where('line_role', 'child')
            ->with('epc')
            ->orderBy('id')
            ->get();

        $this->children = $lines->map(function (PackingScanLine $line): array {
            $epc = $line->epc;
            if ($epc instanceof Epc) {
                $display = Gs1DualDisplay::forEpc($epc);
                $identifier = ($display['gs1_barcode'] ?? '') !== '' && $display['gs1_barcode'] !== '—'
                    ? $display['gs1_barcode']
                    : $this->epcLabel($epc);
                $urn = ($display['urn'] ?? '') !== '' ? $display['urn'] : '—';
            } else {
                $identifier = '#'.$line->epc_id;
                $urn = '—';
            }

            return [
                'epc_id' => (int) $line->epc_id,
                'label' => $identifier,
                'identifier' => $identifier,
                'scanned_at' => $line->confirmed_at?->format('Y-m-d H:i:s')
                    ?? $line->created_at?->format('Y-m-d H:i:s')
                    ?? '—',
                'urn' => $urn,
                'present' => true,
            ];
        })->values()->all();

        return $this->children;
    }

    protected function stagePackingChildScan(PackingSession $session, string $scan): bool
    {
        $result = app(StagePackingScan::class)->handle($session, $scan, 'child', auth()->id());

        if ($result['ok']) {
            return true;
        }

        $block = EpcExclusiveBlock::fromScanResult($result);
        if ($block !== null) {
            $this->flashExclusiveBlock($block, $scan);
        } else {
            $this->flash('warn', (string) $result['message']);
            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'warn');
        }

        return false;
    }

    protected function removePackingStagedChild(int $epcId): void
    {
        $session = $this->packingSession();
        if ($session === null) {
            return;
        }

        $line = PackingScanLine::query()
            ->where('packing_session_id', $session->getKey())
            ->where('epc_id', $epcId)
            ->where('status', 'staged')
            ->first();

        if ($line !== null) {
            app(UnstagePackingScanLine::class)->handle($line);
        }

        $this->hydratePackingChildrenFromDatabase();
    }

    protected function clearPackingSessionState(): void
    {
        $session = $this->packingSession();
        if ($session !== null && $session->status === 'open') {
            app(DeletePackingSession::class)->handle($session);
        }

        $this->packingSessionId = null;
    }

    protected function completePackingSession(): void
    {
        $session = $this->packingSession();
        if ($session !== null) {
            app(CompletePackingSession::class)->handle($session, auth()->id());
            $this->packingSessionId = null;
        }
    }

    protected function hydrateSelectedChildIdsFromPackingSession(): void
    {
        $session = $this->packingSession();
        if ($session === null || ! property_exists($this, 'selectedChildIds')) {
            return;
        }

        $this->selectedChildIds = PackingScanLine::query()
            ->where('packing_session_id', $session->getKey())
            ->where('status', 'staged')
            ->where('line_role', 'child')
            ->orderBy('id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    protected function reservePackingChildByEpcId(int $epcId): bool
    {
        $siteId = $this->resolvePackingSiteId();
        if ($siteId === null) {
            return false;
        }

        $epc = Epc::query()->find($epcId);
        if (! $epc instanceof Epc || blank($epc->epc_uri)) {
            return false;
        }

        if ($this->refuseIfEpcReserved($epc, (string) $epc->epc_uri, 'error')) {
            return false;
        }

        $session = $this->ensurePackingSession($siteId);
        $this->persistPackingSessionParent($session);

        $result = app(StagePackingScan::class)->handle(
            $session,
            (string) $epc->epc_uri,
            'child',
            auth()->id(),
        );

        if (! $result['ok'] && ($result['effect'] ?? '') !== 'already_staged') {
            $this->flash('error', (string) $result['message']);

            return false;
        }

        return true;
    }

    protected function resolvePackingSiteId(): ?int
    {
        if (property_exists($this, 'lockedCommissionSiteId') && $this->lockedCommissionSiteId !== null) {
            return (int) $this->lockedCommissionSiteId;
        }

        if (method_exists($this, 'commissionSite')) {
            $site = $this->commissionSite();

            return $site?->getKey() !== null ? (int) $site->getKey() : null;
        }

        return CurrentSite::id();
    }

    protected function persistPackingSessionParent(PackingSession $session): void
    {
        $updates = [];

        if (property_exists($this, 'parentLabelId') && $this->parentLabelId !== null) {
            $updates['parent_label_id'] = $this->parentLabelId;
        }

        if (property_exists($this, 'parentSscc18') && $this->parentSscc18 !== null) {
            $updates['parent_sscc18'] = $this->parentSscc18;
        }

        if (property_exists($this, 'parentEpcId') && $this->parentEpcId !== null) {
            $updates['parent_epc_id'] = $this->parentEpcId;
        }

        if ($updates !== []) {
            $session->forceFill($updates)->save();
        }
    }
}

<?php

namespace App\Filament\App\Pages\Concerns;

use App\Actions\Disposition\CompleteDispositionSession;
use App\Actions\Disposition\DeleteDispositionSession;
use App\Actions\Disposition\OpenDispositionSession;
use App\Actions\Disposition\StageDispositionScan;
use App\Actions\Disposition\UnstageDispositionScanLine;
use App\Models\Disposition\DispositionScanLine;
use App\Models\Disposition\DispositionSession;
use App\Models\Epcis\Epc;
use App\Support\Floor\EpcExclusiveBlock;
use App\Support\Floor\EpcExclusiveSessionGate;
use App\Support\Floor\ExclusiveSessionContext;
use App\Support\Floor\ResolveOpenFloorSessionUrl;
use Livewire\Attributes\Locked;

trait InteractsWithDispositionWorkstationSession
{
    #[Locked]
    public ?int $dispositionSessionId = null;

    abstract protected function dispositionBizStep(): string;

    public function mountInteractsWithDispositionWorkstationSession(): void
    {
        $sessionId = request()->query('session');
        if (filled($sessionId)) {
            $this->dispositionSessionId = (int) $sessionId;
            $this->hydrateDispositionListFromDatabase();
        }
    }

    protected function dispositionSession(): ?DispositionSession
    {
        if ($this->dispositionSessionId === null) {
            return null;
        }

        return DispositionSession::query()->find($this->dispositionSessionId);
    }

    protected function ensureDispositionSession(int $siteId): DispositionSession
    {
        $session = app(OpenDispositionSession::class)->handle(
            $this->dispositionBizStep(),
            $siteId,
            auth()->id(),
            $this->dispositionSessionId,
        );

        $this->dispositionSessionId = (int) $session->getKey();
        $this->syncDispositionSessionQueryString();

        return $session;
    }

    protected function dispositionExclusiveContext(): ExclusiveSessionContext
    {
        return ExclusiveSessionContext::forOptionalDisposition($this->dispositionSession());
    }

    protected function refuseIfEpcReserved(Epc $epc, string $scan, string $tone = 'error'): bool
    {
        $block = app(EpcExclusiveSessionGate::class)->check($epc, $this->dispositionExclusiveContext());
        if ($block === null) {
            return false;
        }

        $this->flashExclusiveBlock($block, $scan, $tone);

        return true;
    }

    protected function flashExclusiveBlock(EpcExclusiveBlock $block, string $scan, string $tone = 'error'): void
    {
        $url = app(ResolveOpenFloorSessionUrl::class)->urlFromBlock($block, ['scan' => $scan]);
        $this->flash($tone, $block->messageWithOpenHint($url));
        $this->scan = '';
        $this->dispatch('focus-scan');
    }

    protected function syncDispositionSessionQueryString(): void
    {
        if ($this->dispositionSessionId === null || ! method_exists(static::class, 'getUrl')) {
            return;
        }

        $url = static::getUrl(['session' => $this->dispositionSessionId], panel: 'app');
        $this->js('history.replaceState(history.state, "", '.json_encode($url).')');
    }

    /**
     * @return list<array{epc_id: int, label: string}>
     */
    protected function hydrateDispositionListFromDatabase(): array
    {
        $session = $this->dispositionSession();
        if ($session === null) {
            return [];
        }

        $lines = DispositionScanLine::query()
            ->where('disposition_session_id', $session->getKey())
            ->where('status', 'staged')
            ->with('epc')
            ->orderBy('id')
            ->get();

        $this->confirmed = $lines->map(function (DispositionScanLine $line): array {
            $epc = $line->epc;

            return [
                'epc_id' => (int) $line->epc_id,
                'label' => $epc instanceof Epc ? $this->epcLabel($epc) : '#'.$line->epc_id,
            ];
        })->values()->all();

        return $this->confirmed;
    }

    protected function stageDispositionScan(DispositionSession $session, string $scan, Epc $epc): bool
    {
        $result = app(StageDispositionScan::class)->handle($session, $scan, auth()->id());

        if ($result['ok']) {
            return true;
        }

        $block = EpcExclusiveBlock::fromScanResult($result);
        if ($block !== null) {
            $this->flashExclusiveBlock($block, $scan);
        } else {
            $this->flash('error', (string) $result['message']);
            $this->scan = '';
            $this->dispatch('focus-scan');
        }

        return false;
    }

    protected function removeDispositionStaged(int $epcId): void
    {
        $session = $this->dispositionSession();
        if ($session === null) {
            return;
        }

        $line = DispositionScanLine::query()
            ->where('disposition_session_id', $session->getKey())
            ->where('epc_id', $epcId)
            ->where('status', 'staged')
            ->first();

        if ($line !== null) {
            app(UnstageDispositionScanLine::class)->handle($line);
        }

        $this->hydrateDispositionListFromDatabase();
    }

    protected function clearDispositionSession(): void
    {
        $session = $this->dispositionSession();
        if ($session !== null && $session->status === 'open') {
            app(DeleteDispositionSession::class)->handle($session);
        }

        $this->dispositionSessionId = null;
        $this->confirmed = [];
    }

    protected function completeDispositionSession(): void
    {
        $session = $this->dispositionSession();
        if ($session !== null) {
            app(CompleteDispositionSession::class)->handle($session, auth()->id());
            $this->dispositionSessionId = null;
        }
    }
}

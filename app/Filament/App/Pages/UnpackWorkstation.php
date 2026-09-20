<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Enums\PackingSessionKind;
use App\Filament\App\Pages\Concerns\InteractsWithPackingWorkstationSession;
use App\Filament\Notifications\Notification;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Site;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Custody\ResolvesFloorSitePrincipal;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Packing\AcquirePackChildLocks;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\Tracing\Gs1DualDisplay;
use DomainException;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;
use UnitEnum;

class UnpackWorkstation extends Page implements HasKnowledgeBase
{
    use InteractsWithPackingWorkstationSession;
    use ResolvesFloorSitePrincipal;

    /** @var list<array{epc_id: int, label: string}> */
    #[Locked]
    public array $children = [];

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCubeTransparent;

    protected static ?string $navigationLabel = 'Unpack';

    protected static ?string $title = 'Unpack';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.unpack-workstation';

    public string $scan = '';

    public ?int $parentEpcId = null;

    public ?string $parentLabel = null;

    /** @var array<int, string> */
    public array $openChildren = [];

    /** @var list<int> Stable order of open children as loaded (unselected zone). */
    public array $openChildrenOrder = [];

    /** @var list<int|string> Recent-first selection (selected zone). */
    public array $selectedChildIds = [];

    public int $hiddenChildrenCount = 0;

    public bool $showPostUnpackHandoff = false;

    public ?string $lastMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastTone = null;

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());
        $policy = ReceivingPolicy::forTenant(tenant());

        return ($features->supportsUnpacking() || $policy->canUnpackAtReceive())
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public function mount(): void
    {
        $this->mountInteractsWithPackingWorkstationSession();
        $this->restoreUnpackSessionState();
    }

    protected function packingSessionKind(): PackingSessionKind
    {
        return PackingSessionKind::Unpack;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Break a case here. Build a mixed SSCC on Pack.';
    }

    public function processScan(ResolveEpcFromScan $resolveEpcFromScan): void
    {
        $scan = ElementString::normalize(trim($this->scan));
        $this->scan = $scan;
        $this->showPostUnpackHandoff = false;

        if ($scan === '') {
            $this->flash('error', 'Scan a parent or child barcode.');
            $this->dispatch('focus-scan');

            return;
        }

        $resolved = $resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'] ?? null;

        if (! $epc instanceof Epc) {
            $this->flash('error', 'No EPC found for that scan.');
            $this->dispatch('focus-scan');

            return;
        }

        $shippable = app(ShippableEpcsAtSite::class);

        if ($this->parentEpcId !== null) {
            $epcId = (int) $epc->getKey();

            if (array_key_exists($epcId, $this->openChildren)) {
                $this->toggleChild($epcId);
                $this->scan = '';
                $this->dispatch('focus-scan');

                return;
            }

            if ($epcId === $this->parentEpcId) {
                $this->flash('warn', 'Parent locked — select children, then confirm unpack.');
                $this->scan = '';
                $this->dispatch('focus-scan');

                return;
            }

            $this->flash('warn', 'Scan is not the current parent or an open child.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        if ($this->tryChildFirstResolve($epc, $shippable)) {
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        $this->loadParent($epc, $shippable);
        $this->scan = '';
        $this->dispatch('focus-scan');
    }

    public function toggleChild(int $childId): void
    {
        if (! array_key_exists($childId, $this->openChildren)) {
            return;
        }

        $selected = array_values(array_unique(array_map('intval', $this->selectedChildIds)));
        if (in_array($childId, $selected, true)) {
            $this->selectedChildIds = array_values(array_filter(
                $selected,
                fn (int $id): bool => $id !== $childId,
            ));
            $this->removePackingStagedChild($childId);
            $this->flash('ok', 'Returned to container.');
        } else {
            if (! $this->reservePackingChildByEpcId($childId)) {
                return;
            }

            $this->selectedChildIds = array_values(array_unique([
                $childId,
                ...array_values(array_filter($selected, fn (int $id): bool => $id !== $childId)),
            ]));
            $this->flash('ok', 'Selected for unpack.');
        }
    }

    /**
     * @return array<int, string>
     */
    public function selectedChildren(): array
    {
        $out = [];
        foreach (array_map('intval', $this->selectedChildIds) as $id) {
            if (array_key_exists($id, $this->openChildren)) {
                $out[$id] = $this->openChildren[$id];
            }
        }

        return $out;
    }

    /**
     * @return list<array{epc_id: int, identifier: string, scanned_at: string, urn: string, present: bool}>
     */
    public function selectedScanRows(): array
    {
        $ids = array_keys($this->selectedChildren());
        if ($ids === []) {
            return [];
        }

        $epcs = Epc::query()
            ->whereIn('id', $ids)
            ->with('ilmd')
            ->get()
            ->keyBy(fn (Epc $epc): int => (int) $epc->getKey());

        $rows = [];
        foreach ($ids as $id) {
            $epc = $epcs->get($id);
            if ($epc instanceof Epc) {
                $display = Gs1DualDisplay::forEpc($epc);
                $rows[] = [
                    'epc_id' => (int) $id,
                    'identifier' => ($display['gs1_barcode'] ?? '') !== '' ? $display['gs1_barcode'] : '—',
                    'scanned_at' => '—',
                    'urn' => ($display['urn'] ?? '') !== '' ? $display['urn'] : '—',
                    'present' => true,
                ];
            } else {
                $rows[] = [
                    'epc_id' => (int) $id,
                    'identifier' => $this->openChildren[$id] ?? '—',
                    'scanned_at' => '—',
                    'urn' => '—',
                    'present' => true,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function containerChildren(): array
    {
        $selected = array_map('intval', $this->selectedChildIds);
        $out = [];

        foreach ($this->openChildrenOrder as $id) {
            $id = (int) $id;
            if (! in_array($id, $selected, true) && array_key_exists($id, $this->openChildren)) {
                $out[$id] = $this->openChildren[$id];
            }
        }

        foreach ($this->openChildren as $id => $label) {
            $id = (int) $id;
            if (! in_array($id, $selected, true) && ! array_key_exists($id, $out)) {
                $out[$id] = $label;
            }
        }

        return $out;
    }

    public function confirmUnpackAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('confirmUnpack')
                ->label(fn (): string => $this->selectedCount() === 0
                    ? 'Confirm unpack'
                    : 'Confirm unpack ('.$this->selectedCount().')')
                ->color('primary')
                ->disabled(fn (): bool => $this->selectedCount() === 0)
                ->requiresConfirmation()
                ->modalHeading('Unpack selected children?')
                ->modalDescription(function (): string {
                    $count = $this->selectedCount();
                    $parent = $this->parentLabel ?? 'this parent';
                    $noun = $count === 1 ? 'child' : 'children';
                    $siteName = $this->commissionSite()?->name ?? '(select a site)';

                    return "Unpack {$count} {$noun} from {$parent} at commission site {$siteName}? This authors AggregationEvent DELETE(s) and closes those open links.";
                })
                ->modalSubmitActionLabel('Unpack')
                ->action(function (UnpackReceivingHierarchy $unpack, ShippableEpcsAtSite $shippable): void {
                    $this->performUnpack($unpack, $shippable);
                }),
            'unpack_workstation_partial_unpack',
            requireReason: false,
        );
    }

    public function unpackAllAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('unpackAll')
                ->label('Unpack all')
                ->color('danger')
                ->disabled(fn (): bool => $this->openChildren === [])
                ->requiresConfirmation()
                ->modalHeading('Unpack all open children?')
                ->modalDescription('Selects every open child under this parent and authors AggregationEvent DELETE(s).')
                ->modalSubmitActionLabel('Unpack all')
                ->action(function (UnpackReceivingHierarchy $unpack, ShippableEpcsAtSite $shippable): void {
                    $this->selectedChildIds = array_map('strval', array_keys($this->openChildren));
                    $this->performUnpack($unpack, $shippable);
                }),
            'unpack_workstation_unpack_all',
            requireReason: false,
        );
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->confirmUnpackAction(),
            $this->unpackAllAction(),
        ];
    }

    public function performUnpack(UnpackReceivingHierarchy $unpack, ShippableEpcsAtSite $shippable): void
    {
        $parent = $this->resolvedParent();
        if ($parent === null) {
            $this->flash('error', 'Scan a parent first.');

            return;
        }

        $selected = array_values(array_unique(array_map('intval', $this->selectedChildIds)));
        if ($selected === []) {
            $this->flash('error', 'Select at least one child to unpack.');

            return;
        }

        $site = $this->commissionSite();
        if ($site === null) {
            $this->flash('error', 'Select a commission site (site chooser) before unpacking.');

            return;
        }

        $siteId = (int) $site->getKey();
        if (! $this->assertSiteAccess($siteId)) {
            return;
        }

        if (! $shippable->contains($siteId, (int) $parent->getKey(), $this->floorPrincipalId($siteId))) {
            $this->flash('error', 'Parent is not on hand at the selected site.');

            return;
        }

        $parentHold = app(ReceivingGate::class)->epcBlockedByOpenHold($parent);
        if ($parentHold !== null) {
            $this->flash('error', 'Parent is quarantined and cannot be unpacked.');

            return;
        }

        $selected = array_values(array_intersect(
            $selected,
            array_map('intval', array_keys($this->openChildren)),
        ));

        if ($selected === []) {
            $this->flash('error', 'Selected children are no longer open — rescan.');

            return;
        }

        $stillOpenChildIds = AggregationLink::query()
            ->where('parent_epc_id', $parent->getKey())
            ->whereNull('valid_to')
            ->whereIn('child_epc_id', $selected)
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (count($stillOpenChildIds) !== count($selected)) {
            $this->loadParent($parent, app(ShippableEpcsAtSite::class));
            $this->flash('error', 'Selected children are no longer open — rescan.');

            return;
        }

        $selected = $stillOpenChildIds;

        foreach ($selected as $childId) {
            if (! $shippable->contains($siteId, $childId, $this->floorPrincipalId($siteId))) {
                $this->flash('error', 'A selected child is not on hand at the selected site — rescan.');

                return;
            }
        }

        $locks = app(AcquirePackChildLocks::class)->acquire($selected);

        if ($locks === null) {
            $this->flash('error', 'Another pack or unpack is in progress for one of these children. Try again in a moment.');

            return;
        }

        try {
            $result = $unpack->handleParent(
                $parent,
                $selected,
                $site,
                auth()->id(),
                $this->floorPrincipalId($siteId),
            );
        } catch (DomainException|InvalidArgumentException|Throwable $exception) {
            $this->flash('error', $exception->getMessage());

            Notification::make()
                ->title('Unpack failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            if ($locks !== null) {
                app(AcquirePackChildLocks::class)->release($locks);
            }
        }

        $closed = (int) ($result['closed_links'] ?? 0);
        $successTone = $closed > 0 ? 'ok' : 'warn';
        $successMessage = $closed > 0
            ? "Unpacked {$closed} child link".($closed === 1 ? '' : 's').' (unpacking).'
            : 'No open links matched the selection.';
        $this->flash($successTone, $successMessage);

        $notification = Notification::make()
            ->title($closed > 0 ? 'Unpack complete' : 'Nothing unpacked')
            ->body($successMessage);

        if ($closed > 0) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();

        if ($closed > 0) {
            $this->completePackingSession();
        }

        $this->loadParent($parent, app(ShippableEpcsAtSite::class));
        $this->showPostUnpackHandoff = $closed > 0;
        $this->lastTone = $successTone;
        $this->lastMessage = $successMessage;
        $this->dispatch('focus-scan');
        $this->dispatch('scan-result', tone: $this->lastTone);
    }

    public function clearParent(): void
    {
        $this->clearPackingSessionState();
        $this->parentEpcId = null;
        $this->parentLabel = null;
        $this->openChildren = [];
        $this->openChildrenOrder = [];
        $this->selectedChildIds = [];
        $this->hiddenChildrenCount = 0;
        $this->showPostUnpackHandoff = false;
        $this->flash('ok', 'Cleared parent.');
        $this->dispatch('focus-scan');
    }

    public function selectedCount(): int
    {
        return count(array_values(array_unique(array_map('intval', $this->selectedChildIds))));
    }

    public function openChildrenCount(): int
    {
        return count($this->openChildren);
    }

    public function commissionSiteLabel(): string
    {
        $site = $this->commissionSite();

        return $site?->name ?? 'No site selected';
    }

    public function packWorkstationUrl(): ?string
    {
        if (! PackWorkstation::canAccess()) {
            return null;
        }

        return PackWorkstation::getUrl();
    }

    public function unpackedItemsUrl(): ?string
    {
        if (! UnpackedItems::canAccess()) {
            return null;
        }

        return UnpackedItems::getUrl();
    }

    public function breakPackWorkstationUrl(): ?string
    {
        if (! BreakPackWorkstation::canAccess()) {
            return null;
        }

        return BreakPackWorkstation::getUrl();
    }

    /**
     * TraceLink-style child-first: scanning a child with no parent loaded
     * resolves the open parent and selects that child (unless the scan is itself a parent with open children).
     */
    private function tryChildFirstResolve(Epc $epc, ShippableEpcsAtSite $shippable): bool
    {
        $epcId = (int) $epc->getKey();

        $hasOpenChildren = AggregationLink::query()
            ->where('parent_epc_id', $epcId)
            ->whereNull('valid_to')
            ->exists();

        if ($hasOpenChildren) {
            return false;
        }

        $parentId = AggregationLink::query()
            ->where('child_epc_id', $epcId)
            ->whereNull('valid_to')
            ->value('parent_epc_id');

        if ($parentId === null) {
            return false;
        }

        $parent = Epc::query()->find((int) $parentId);
        if (! $parent instanceof Epc) {
            return false;
        }

        $this->loadParent($parent, $shippable);

        if ($this->parentEpcId === null) {
            return true;
        }

        if (array_key_exists($epcId, $this->openChildren)) {
            $selected = array_map('intval', $this->selectedChildIds);
            if (! in_array($epcId, $selected, true)) {
                if (! $this->reservePackingChildByEpcId($epcId)) {
                    return true;
                }

                $this->selectedChildIds = array_values(array_unique([
                    $epcId,
                    ...$selected,
                ]));
            }
            $this->flash('warn', 'Parent loaded — child selected for unpack.');
        }

        return true;
    }

    private function loadParent(Epc $parent, ShippableEpcsAtSite $shippable): void
    {
        $site = $this->commissionSite();
        if ($site === null) {
            $this->flash('error', 'Select a commission site (site chooser) before scanning.');
            $this->parentEpcId = null;
            $this->parentLabel = null;
            $this->openChildren = [];
            $this->openChildrenOrder = [];
            $this->selectedChildIds = [];
            $this->hiddenChildrenCount = 0;

            return;
        }

        $siteId = (int) $site->getKey();
        if (! $this->assertSiteAccess($siteId)) {
            return;
        }

        if (! $shippable->contains($siteId, (int) $parent->getKey(), $this->floorPrincipalId($siteId))) {
            $this->flash('error', 'Parent is not on hand at the selected site.');

            return;
        }

        if ($this->refuseIfEpcReserved($parent, (string) $parent->epc_uri, 'error')) {
            return;
        }

        $this->parentEpcId = (int) $parent->getKey();
        $this->parentLabel = $this->epcLabel($parent);
        $this->openChildren = app(UnpackReceivingHierarchy::class)->openChildOptionsForParent($parent);
        $this->openChildrenOrder = array_map('intval', array_keys($this->openChildren));
        $this->selectedChildIds = [];

        $session = $this->ensurePackingSession($siteId);
        $this->persistPackingSessionParent($session);
        if (! $this->reservePackingParentScan($session, $parent)) {
            $this->clearPackingSessionState();
            $this->parentEpcId = null;
            $this->parentLabel = null;
            $this->openChildren = [];
            $this->openChildrenOrder = [];
            $this->selectedChildIds = [];
            $this->hiddenChildrenCount = 0;

            return;
        }

        $totalOpen = (int) AggregationLink::query()
            ->where('parent_epc_id', $parent->getKey())
            ->whereNull('valid_to')
            ->count();
        $this->hiddenChildrenCount = max(0, $totalOpen - count($this->openChildren));

        if ($this->openChildren === []) {
            $message = 'Parent loaded — no open children to unpack.';
            if ($this->hiddenChildrenCount > 0) {
                $message .= ' '.$this->hiddenChildrenCount.' child'
                    .($this->hiddenChildrenCount === 1 ? '' : 'ren')
                    .' hidden (hold/custody).';
            }
            $this->flash('warn', $message);
        } else {
            $this->flash('warn', 'Parent loaded — select children to unpack.');
        }
    }

    private function resolvedParent(): ?Epc
    {
        if ($this->parentEpcId === null) {
            return null;
        }

        return Epc::query()->find($this->parentEpcId);
    }

    private function restoreUnpackSessionState(): void
    {
        $session = $this->packingSession();
        if ($session === null) {
            return;
        }

        if ($session->parent_epc_id !== null) {
            $parent = Epc::query()->find($session->parent_epc_id);
            if ($parent instanceof Epc) {
                $this->loadParent($parent, app(ShippableEpcsAtSite::class));
            }
        }

        $this->hydrateSelectedChildIdsFromPackingSession();
    }

    private function commissionSite(): ?Site
    {
        $siteId = CurrentSite::preferredId(
            null,
            EligibleReceiveSites::organizationOptions(),
        );

        return $siteId !== null ? Site::query()->find($siteId) : null;
    }

    private function assertSiteAccess(int $siteId): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            $this->flash('error', 'You must be signed in to use this workstation.');

            return false;
        }

        try {
            SiteAccess::assertCanAccessSite($user, $siteId);
        } catch (AuthorizationException $exception) {
            $this->flash('error', $exception->getMessage());

            return false;
        }

        return true;
    }

    private function epcLabel(Epc $epc): string
    {
        return EpcBarcodeDisplay::forEpc($epc);
    }

    private function flash(string $tone, string $message): void
    {
        $this->lastTone = $tone;
        $this->lastMessage = $message;
        $this->dispatch('scan-result', tone: $tone);
    }

    public static function getDocumentation(): array|string
    {
        return 'workflows.unpack';
    }
}

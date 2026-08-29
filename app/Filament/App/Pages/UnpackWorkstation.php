<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Receiving\UnpackReceivingHierarchy;
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
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Packing\AcquirePackChildLocks;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

class UnpackWorkstation extends Page implements HasKnowledgeBase
{
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

    /** @var list<int|string> */
    public array $selectedChildIds = [];

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

    public function getSubheading(): string|Htmlable|null
    {
        return 'Break a case here. Build a mixed SSCC on Pack.';
    }

    public function processScan(ResolveEpcFromScan $resolveEpcFromScan): void
    {
        $scan = ElementString::normalize(trim($this->scan));
        $this->scan = $scan;

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

        if ($this->parentEpcId !== null) {
            $epcId = (int) $epc->getKey();

            if (array_key_exists($epcId, $this->openChildren)) {
                $this->toggleChild($epcId);
                $this->scan = '';
                $this->dispatch('focus-scan');

                return;
            }

            if ($epcId === $this->parentEpcId) {
                $this->flash('ok', 'Parent confirmed.');
                $this->scan = '';
                $this->dispatch('focus-scan');

                return;
            }

            $this->flash('warn', 'Scan is not the current parent or an open child.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        $this->loadParent($epc, app(ShippableEpcsAtSite::class));
        $this->scan = '';
        $this->dispatch('focus-scan');
    }

    public function toggleChild(int $childId): void
    {
        $selected = array_map('intval', $this->selectedChildIds);
        if (in_array($childId, $selected, true)) {
            $this->selectedChildIds = array_values(array_filter(
                $selected,
                fn (int $id): bool => $id !== $childId,
            ));
            $this->flash('ok', 'Removed from selection.');
        } else {
            $selected[] = $childId;
            $this->selectedChildIds = array_values(array_unique($selected));
            $this->flash('ok', 'Added to selection.');
        }
    }

    public function confirmUnpackAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('confirmUnpack')
                ->label('Confirm unpack')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Unpack selected children?')
                ->modalDescription(function (): string {
                    $count = count(array_values(array_unique(array_map('intval', $this->selectedChildIds))));
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
                ->color('warning')
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

        if (! $shippable->contains($siteId, (int) $parent->getKey())) {
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
            if (! $shippable->contains($siteId, $childId)) {
                $this->flash('error', 'A selected child is not on hand at the selected site — rescan.');

                return;
            }
        }

        $locks = app(AcquirePackChildLocks::class)->acquire($selected);

        if ($locks === null) {
            $this->flash('error', 'Another pack is in progress for one of these children. Try again in a moment.');

            return;
        }

        try {
            $result = $unpack->handleParent(
                $parent,
                $selected,
                $site,
                auth()->id(),
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
            ? "Unpacked {$closed} child link".($closed === 1 ? '' : 's').'.'
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

        $this->loadParent($parent, app(ShippableEpcsAtSite::class));
        $this->lastTone = $successTone;
        $this->lastMessage = $successMessage;
        $this->dispatch('focus-scan');
        $this->dispatch('scan-result', tone: $this->lastTone);
    }

    public function clearParent(): void
    {
        $this->parentEpcId = null;
        $this->parentLabel = null;
        $this->openChildren = [];
        $this->selectedChildIds = [];
        $this->flash('ok', 'Cleared parent.');
        $this->dispatch('focus-scan');
    }

    private function loadParent(Epc $parent, ShippableEpcsAtSite $shippable): void
    {
        $site = $this->commissionSite();
        if ($site === null) {
            $this->flash('error', 'Select a commission site (site chooser) before scanning.');
            $this->parentEpcId = null;
            $this->parentLabel = null;
            $this->openChildren = [];
            $this->selectedChildIds = [];

            return;
        }

        $siteId = (int) $site->getKey();
        if (! $this->assertSiteAccess($siteId)) {
            return;
        }

        if (! $shippable->contains($siteId, (int) $parent->getKey())) {
            $this->flash('error', 'Parent is not on hand at the selected site.');

            return;
        }

        $this->parentEpcId = (int) $parent->getKey();
        $this->parentLabel = $this->epcLabel($parent);
        $this->openChildren = app(UnpackReceivingHierarchy::class)->openChildOptionsForParent($parent);
        $this->selectedChildIds = [];

        if ($this->openChildren === []) {
            $this->flash('warn', 'Parent loaded — no open children to unpack.');
        } else {
            $this->flash('ok', 'Parent loaded — select children to unpack.');
        }
    }

    private function resolvedParent(): ?Epc
    {
        if ($this->parentEpcId === null) {
            return null;
        }

        return Epc::query()->find($this->parentEpcId);
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

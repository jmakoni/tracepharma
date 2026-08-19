<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Labeling\BreakPalletAndReship;
use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Actions\Receiving\UnpackReceivingHierarchy;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccReshipMode;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Labeling\PreviewNextSsccLabels;
use App\Support\Packing\AcquirePackChildLocks;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\TenantSsccSettings;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

class BreakPackWorkstation extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Break & pack';

    protected static ?string $title = 'Break & pack';

    protected static ?int $navigationSort = 14;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.break-pack-workstation';

    public string $scan = '';

    public ?int $parentEpcId = null;

    public ?string $parentLabel = null;

    public ?string $parentUrn = null;

    public ?int $sourceDocumentId = null;

    /** @var array<int, string> */
    public array $openChildren = [];

    /** @var list<int|string> */
    public array $selectedChildIds = [];

    public ?string $lastMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastTone = null;

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsPacking())
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Break children from a source pallet and commission a new parent SSCC under your organization company prefix.';
    }

    public function tenantNameDisplay(): string
    {
        return (string) (tenant()?->name ?? 'This organization');
    }

    public function companyPrefixDisplay(): string
    {
        return (string) (TenantSsccSettings::resolve()['company_prefix'] ?? 'Configure in Organization settings');
    }

    public function processScan(
        ResolveEpcFromScan $resolveEpcFromScan,
        EpcCustodyGate $custodyGate,
        ShippableEpcsAtSite $shippable,
    ): void {
        $scan = ElementString::normalize(trim($this->scan));
        $this->scan = $scan;

        if ($scan === '') {
            $this->flash('error', 'Scan a source parent or child barcode.');
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
                // Deselecting is always allowed; only adding a child to the pack needs custody.
                $selecting = ! in_array($epcId, array_map('intval', $this->selectedChildIds), true);

                if ($selecting) {
                    if (! $this->passesOnHandGate($shippable, $epcId)) {
                        return;
                    }

                    if (! $this->passesCustodyGate($custodyGate, $epc)) {
                        return;
                    }
                }

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

        if (! $this->passesOnHandGate($shippable, (int) $epc->getKey())) {
            return;
        }

        if (! $this->passesCustodyGate($custodyGate, $epc)) {
            return;
        }

        $this->loadParent($epc, $shippable);
        $this->scan = '';
        $this->dispatch('focus-scan');
    }

    /**
     * Custody is re-checked under lock at confirm time; failing here keeps the operator
     * from staging a pack the gate will reject after the source pallet is already broken.
     */
    private function passesCustodyGate(EpcCustodyGate $custodyGate, Epc $epc): bool
    {
        try {
            $custodyGate->assertOperableFor($epc, 'break and pack');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->scan = '';
            $this->dispatch('focus-scan');

            return false;
        }

        return true;
    }

    private function passesOnHandGate(ShippableEpcsAtSite $shippable, int $epcId): bool
    {
        $site = $this->commissionSite();
        if ($site === null) {
            $this->flash('error', 'Select a commission site (site chooser) before scanning.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return false;
        }

        if (! $this->assertSiteAccess((int) $site->getKey())) {
            $this->scan = '';
            $this->dispatch('focus-scan');

            return false;
        }

        if (! $shippable->contains((int) $site->getKey(), $epcId)) {
            $this->flash('error', 'Not on hand at the selected site.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return false;
        }

        return true;
    }

    public function toggleChild(int $childId): void
    {
        $selected = array_map('intval', $this->selectedChildIds);
        if (in_array($childId, $selected, true)) {
            $this->selectedChildIds = array_values(array_filter(
                $selected,
                fn (int $id): bool => $id !== $childId,
            ));
        } else {
            $selected[] = $childId;
            $this->selectedChildIds = array_values(array_unique($selected));
        }
    }

    public function clearParent(): void
    {
        $this->resetParentState();
        $this->flash('ok', 'Cleared source parent.');
        $this->dispatch('focus-scan');
    }

    private function resetParentState(): void
    {
        $this->parentEpcId = null;
        $this->parentLabel = null;
        $this->parentUrn = null;
        $this->sourceDocumentId = null;
        $this->openChildren = [];
        $this->selectedChildIds = [];
    }

    public function confirmBreakPackAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('confirmBreakPack')
                ->label('Confirm break & pack')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Commission new parent SSCC?')
                ->modalDescription(fn (): string => $this->breakPackConfirmationDescription())
                ->modalSubmitActionLabel('Commission parent')
                ->action(function (
                    BreakPalletAndReship $breakPallet,
                    GenerateSsccLabelBatch $generate,
                    UnpackReceivingHierarchy $unpack,
                    ShippableEpcsAtSite $shippable,
                ): void {
                    $this->performBreakPack($breakPallet, $generate, $unpack, $shippable);
                }),
            'break_pack_workstation_commission_parent',
            requireReason: false,
        );
    }

    public function performBreakPack(
        BreakPalletAndReship $breakPallet,
        GenerateSsccLabelBatch $generate,
        UnpackReceivingHierarchy $unpack,
        ShippableEpcsAtSite $shippable,
    ): void {
        $parent = $this->resolvedParent();
        if ($parent === null || blank($this->parentUrn)) {
            $this->flash('error', 'Scan a source parent first.');

            return;
        }

        $openChildKeys = array_map('intval', array_keys($this->openChildren));
        $selectedIds = array_values(array_unique(array_map('intval', $this->selectedChildIds)));
        if ($selectedIds === []) {
            $this->flash('error', 'Select at least one child to break & pack.');

            return;
        }

        $selectedIds = array_values(array_intersect($selectedIds, $openChildKeys));

        if ($selectedIds === []) {
            $this->flash('error', 'Selected children are no longer open under this parent — rescan.');

            return;
        }

        $validChildIds = AggregationLink::query()
            ->where('parent_epc_id', $parent->getKey())
            ->whereNull('valid_to')
            ->whereIn('child_epc_id', $selectedIds)
            ->pluck('child_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($validChildIds === [] || count($validChildIds) !== count($selectedIds)) {
            $this->flash('error', 'Selected children are no longer open under this parent — rescan.');

            return;
        }

        $selectedIds = $validChildIds;

        $selectedUrns = Epc::query()
            ->whereIn('id', $selectedIds)
            ->pluck('epc_uri')
            ->map(fn ($uri): string => (string) $uri)
            ->filter()
            ->values()
            ->all();

        if (count($selectedUrns) !== count($selectedIds)) {
            $this->flash('error', 'Could not resolve every selected child URN — rescan before packing.');

            return;
        }

        $siteId = CurrentSite::preferredId(
            null,
            EligibleReceiveSites::organizationOptions(),
        );

        if ($siteId === null) {
            $this->flash('error', 'Select a commission site (site chooser) before packing.');

            return;
        }

        if (! $this->assertSiteAccess($siteId)) {
            return;
        }

        $onHandError = $this->assertSelectedOnHand($selectedIds, $siteId, $shippable);
        if ($onHandError !== null) {
            $this->flash('error', $onHandError);

            return;
        }

        if (! $shippable->contains($siteId, (int) $parent->getKey())) {
            $this->flash('error', 'Source parent is no longer on hand at the selected site — rescan.');

            return;
        }

        $locks = app(AcquirePackChildLocks::class)->acquire([
            ...$selectedIds,
            (int) $parent->getKey(),
        ]);

        if ($locks === null) {
            $this->flash('error', 'Another pack is in progress for one of these children. Try again in a moment.');

            return;
        }

        $unpackedWithoutPack = false;

        try {
            $onHandError = $this->assertSelectedOnHand($selectedIds, $siteId, $shippable);
            if ($onHandError !== null) {
                throw new InvalidArgumentException($onHandError);
            }

            if ($this->sourceDocumentId !== null && $this->sourceDocumentId > 0) {
                $batch = $breakPallet->execute([
                    'source_epcis_document_id' => $this->sourceDocumentId,
                    'source_parent_sscc_urn' => $this->parentUrn,
                    'selected_child_epcs' => $selectedUrns,
                    'reship_mode' => SsccReshipMode::Combined->value,
                    'allocation_mode' => SsccAllocationMode::Sequential->value,
                    'label_count' => 1,
                    'copies_per_label' => 1,
                    'site_id' => $siteId,
                    'emit_epcis' => true,
                    'emit_disaggregation' => true,
                    'send_to_printer' => false,
                ]);
                $modeNote = 'Break & pack (reship) complete.';
            } else {
                $parentUrn = (string) $this->parentUrn;
                $site = Site::query()->find($siteId);
                if ($site === null) {
                    $this->flash('error', 'Select a commission site (site chooser) before packing.');

                    return;
                }

                // Unpack commits on its own so the SSCC serial pool lock taken by
                // GenerateSsccLabelBatch is never held across PDF rendering.
                $unpackResult = DB::transaction(function () use ($unpack, $parent, $selectedIds, $site): array {
                    $unpackResult = $unpack->handleParent($parent, $selectedIds, $site, auth()->id());

                    $closedLinks = (int) ($unpackResult['closed_links'] ?? 0);

                    if ($closedLinks === 0) {
                        throw new InvalidArgumentException('Children already broken — rescan');
                    }

                    if ($closedLinks !== count($selectedIds)) {
                        throw new InvalidArgumentException(
                            'Only '.$closedLinks.' of '.count($selectedIds).' selected children were still open — '
                            .'nothing was unpacked. Rescan the parent and retry.',
                        );
                    }

                    return $unpackResult;
                });

                $unpackedWithoutPack = true;

                $packEventTime = ($unpackResult['unpackEvent']?->event_time ?? now())->copy()->addSecond();

                $batch = $generate->execute([
                    'allocation_mode' => SsccAllocationMode::Sequential->value,
                    'label_count' => 1,
                    'copies_per_label' => 1,
                    'enforce_forward_only' => true,
                    'site_id' => $siteId,
                    'child_epcs' => implode("\n", $selectedUrns),
                    'source_parent_sscc_urn' => $parentUrn,
                    'emit_epcis' => true,
                    'emit_disaggregation' => false,
                    'send_to_printer' => false,
                    'event_time' => $packEventTime,
                ]);

                $unpackedWithoutPack = false;
                $modeNote = 'Unpacked selected children, then packed onto a new SSCC (no source document).';
            }
        } catch (DomainException|InvalidArgumentException|Throwable $exception) {
            $message = $exception->getMessage();

            if ($unpackedWithoutPack) {
                $message = 'Unpack succeeded but packing failed — the selected children are already broken out '
                    .'of the source parent and are now loose. Pack them onto a new SSCC manually. Cause: '.$message;

                // Stale open-children state would let the operator retry an unpack that already happened.
                $this->resetParentState();
            }

            $this->flash('error', $message);

            Notification::make()
                ->title($unpackedWithoutPack ? 'Packing failed after unpack' : 'Break & pack failed')
                ->body($message)
                ->danger()
                ->send();

            return;
        } finally {
            app(AcquirePackChildLocks::class)->release($locks);
        }

        $batch->refresh(['labels.children']);

        if (! $batch->packingSucceeded()) {
            $detail = implode(' ', $batch->errorLines());
            $message = 'Batch #'.$batch->getKey().' was not fully packed. '.$detail;

            if ($unpackedWithoutPack) {
                $message = 'Unpack succeeded but packing failed — the selected children are already broken out '
                    .'of the source parent and are now loose. Pack them onto a new SSCC manually. Cause: '.$message;
                $this->resetParentState();
            }

            $this->flash('error', $message);

            Notification::make()
                ->title($unpackedWithoutPack ? 'Packing failed after unpack' : 'Break & pack incomplete')
                ->body($message)
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Break & pack complete')
            ->body($modeNote.' Batch #'.$batch->getKey().'.')
            ->success()
            ->send();

        $this->redirect(SsccLabelResource::getUrl('view-batch', ['record' => $batch]));
    }

    private function breakPackConfirmationDescription(): string
    {
        $settings = TenantSsccSettings::resolve();
        $prefix = (string) ($settings['company_prefix'] ?? '');
        $extension = (int) ($settings['extension_digit'] ?? 0);
        $childCount = count(array_unique(array_map('intval', $this->selectedChildIds)));

        $lines = [
            'Organization: '.$this->tenantNameDisplay(),
            'GS1 Company Prefix: '.($prefix !== '' ? $prefix : '(not configured)'),
            'Extension digit: '.$extension,
            'Source parent: '.($this->parentLabel ?? '(none)'),
            "Children selected: {$childCount}",
            'New parent labels: 1',
        ];

        try {
            $siteId = CurrentSite::preferredId(
                null,
                EligibleReceiveSites::organizationOptions(),
            );
            $previews = app(PreviewNextSsccLabels::class)->handle(1, siteId: $siteId);
            $lines[] = 'Next parent SSCC: '.$previews[0];
        } catch (InvalidArgumentException|Throwable $exception) {
            $lines[] = 'Next parent SSCC: '.$exception->getMessage();
        }

        $lines[] = 'New parents use your organization company prefix (not the manufacturer of the children).';
        $lines[] = 'This authors disaggregation and aggregation EPCIS only — no shipping event is created.';

        return implode("\n", $lines);
    }

    private function loadParent(Epc $parent, ShippableEpcsAtSite $shippable): void
    {
        $site = $this->commissionSite();
        if ($site === null) {
            $this->flash('error', 'Select a commission site (site chooser) before scanning.');

            return;
        }

        $siteId = (int) $site->getKey();
        if (! $this->assertSiteAccess($siteId)) {
            return;
        }

        if (! $shippable->contains($siteId, (int) $parent->getKey())) {
            $this->flash('error', 'Source parent is not on hand at the selected site.');

            return;
        }

        $this->parentEpcId = (int) $parent->getKey();
        $this->parentLabel = $this->epcLabel($parent);
        $this->parentUrn = (string) $parent->epc_uri;
        $this->openChildren = app(UnpackReceivingHierarchy::class)->openChildOptionsForParent($parent);
        $this->selectedChildIds = [];
        $this->sourceDocumentId = $this->resolveSourceDocumentId($parent);

        if ($this->openChildren === []) {
            $this->flash('warn', 'Parent loaded — no open children.');
        } elseif ($this->sourceDocumentId !== null) {
            $this->flash('ok', 'Parent loaded from inbound document #'.$this->sourceDocumentId.'.');
        } else {
            $this->flash('warn', 'Parent loaded — source document unknown; confirm will unpack then pack.');
        }
    }

    private function resolveSourceDocumentId(Epc $parent): ?int
    {
        $eventId = AggregationLink::query()
            ->where('parent_epc_id', $parent->getKey())
            ->whereNull('valid_to')
            ->whereNotNull('established_by_event_id')
            ->orderByDesc('id')
            ->value('established_by_event_id');

        if ($eventId === null) {
            return null;
        }

        $documentId = EpcisEvent::query()
            ->whereKey($eventId)
            ->value('document_id');

        return $documentId !== null ? (int) $documentId : null;
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

    /**
     * @param  list<int>  $epcIds
     */
    private function assertSelectedOnHand(array $epcIds, int $siteId, ShippableEpcsAtSite $shippable): ?string
    {
        foreach ($epcIds as $epcId) {
            if (! $shippable->contains($siteId, $epcId)) {
                return 'A selected child is no longer on hand at the selected site — rescan.';
            }
        }

        return null;
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
}

<?php

namespace App\Filament\App\Pages;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Actions\Labeling\AttachChildrenToExistingSscc;
use App\Actions\Labeling\GenerateSsccLabelBatch;
use App\Enums\SsccAllocationMode;
use App\Enums\SsccLabelBatchStatus;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Site;
use App\Models\SsccLabel;
use App\Models\SsccLabelChild;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Labeling\PreviewNextSsccLabels;
use App\Support\Packing\AcquirePackChildLocks;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use App\Support\TenantSsccSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;
use UnitEnum;

class PackWorkstation extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Pack';

    protected static ?string $title = 'Pack';

    protected static ?int $navigationSort = 13;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.pack-workstation';

    public string $scan = '';

    /** @var list<array{epc_id: int, label: string}> */
    #[Locked]
    public array $children = [];

    #[Locked]
    public ?int $lockedCommissionSiteId = null;

    #[Locked]
    public ?int $parentLabelId = null;

    #[Locked]
    public ?string $parentSscc18 = null;

    #[Locked]
    public ?string $parentUrn = null;

    public ?string $lastMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastTone = null;

    public ?string $batchUrl = null;

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsPacking())
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->parentLabelId !== null) {
            return 'Parent SSCC bound. Scan loose bottles (or a child logistics unit), then confirm to ADD them. Break a case on Unpack first.';
        }

        return 'Break a case on Unpack, then scan bottles here. Confirm pack commissions a new SSCC, or scan an already generated SSCC to continue packing.';
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
            $this->flash('error', 'Scan a child SSCC or SGTIN, or an already generated parent SSCC.');
            $this->dispatch('focus-scan');

            return;
        }

        $site = $this->commissionSite();
        if ($site === null) {
            $this->flash('error', 'Select a commission site (site chooser) before scanning.');
            $this->dispatch('focus-scan');

            return;
        }

        if (! $this->assertSiteAccess((int) $site->getKey())) {
            $this->dispatch('focus-scan');

            return;
        }

        if ($this->lockedCommissionSiteId === null) {
            $this->lockedCommissionSiteId = (int) $site->getKey();
        }

        $issuedLabel = $this->issuedLabelForScan($scan, $resolveEpcFromScan);
        if ($issuedLabel instanceof SsccLabel) {
            $this->handleIssuedSsccScan($issuedLabel, $custodyGate);
            $this->dispatch('focus-scan');

            return;
        }

        $resolved = $resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'] ?? null;

        if (! $epc instanceof Epc || blank($epc->epc_uri)) {
            $this->flash('error', 'No EPC found for that scan.');
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        try {
            $custodyGate->assertOperableFor($epc, 'packing');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        $epcId = (int) $epc->getKey();

        if (! $shippable->contains((int) $site->getKey(), $epcId)) {
            $this->flash('error', 'Not on hand at the selected site.');
            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'error');

            return;
        }
        foreach ($this->children as $row) {
            if ((int) $row['epc_id'] === $epcId) {
                $this->flash('warn', 'Already in the pack list.');
                $this->scan = '';
                $this->dispatch('focus-scan');
                $this->dispatch('scan-result', tone: 'warn');

                return;
            }
        }

        if ($this->childAlreadyOnBoundParent($epc)) {
            $this->flash('warn', 'Already on this SSCC.');
            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'warn');

            return;
        }

        $openLink = $this->openParentLinkForChild($epcId);
        if ($openLink !== null && ! $this->openLinkIsBoundParent($openLink)) {
            $parentLabel = $this->parentLabelForLink($openLink);
            $this->flash('warn', "Already packed under {$parentLabel}. Unpack it first, then pack here.");
            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'warn');

            return;
        }

        $labelConflicts = $this->existingLabelConflictsForChildIds([$epcId], $this->parentLabelId);
        if ($labelConflicts !== []) {
            $this->flash('warn', $labelConflicts[0].' Unpack it first, then pack here.');
            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'warn');

            return;
        }

        if (! app(AcquirePackChildLocks::class)->softReserve($epcId)) {
            $this->flash('warn', 'Another operator is packing this unit. Try again shortly.');
            $this->scan = '';
            $this->dispatch('focus-scan');
            $this->dispatch('scan-result', tone: 'warn');

            return;
        }

        $this->children[] = [
            'epc_id' => $epcId,
            'label' => $this->epcLabel($epc),
        ];

        $this->scan = '';
        $this->flash('ok', 'Added '.$this->epcLabel($epc));
        $this->dispatch('focus-scan');
        $this->dispatch('scan-result', tone: 'ok');
    }

    public function removeChild(int $epcId): void
    {
        app(AcquirePackChildLocks::class)->releaseSoftReserve($epcId);

        $this->children = array_values(array_filter(
            $this->children,
            fn (array $row): bool => (int) $row['epc_id'] !== $epcId,
        ));
    }

    public function clearChildren(): void
    {
        app(AcquirePackChildLocks::class)->releaseSoftReserves($this->childIds());

        $this->releaseBoundParentSoftReserve();
        $this->children = [];
        $this->lockedCommissionSiteId = null;
        $this->parentLabelId = null;
        $this->parentSscc18 = null;
        $this->parentUrn = null;
        $this->batchUrl = null;
        $this->flash('ok', 'Cleared pack list.');
        $this->dispatch('focus-scan');
    }

    public function confirmPackAction(): Action
    {
        return Action::make('confirmPack')
            ->label('Confirm pack')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $this->parentLabelId !== null
                ? 'Add children to this SSCC?'
                : 'Commission new parent SSCC?')
            ->modalDescription(fn (): string => $this->packConfirmationDescription())
            ->modalSubmitActionLabel(fn (): string => $this->parentLabelId !== null
                ? 'Add to SSCC'
                : 'Commission parent')
            ->action(function (
                GenerateSsccLabelBatch $generate,
                AttachChildrenToExistingSscc $attach,
                EpcCustodyGate $custodyGate,
                ShippableEpcsAtSite $shippable,
            ): void {
                $this->performPack($generate, $attach, $custodyGate, $shippable);
            });
    }

    public function performPack(
        GenerateSsccLabelBatch $generate,
        AttachChildrenToExistingSscc $attach,
        EpcCustodyGate $custodyGate,
        ShippableEpcsAtSite $shippable,
    ): void {
        if ($this->children === []) {
            $this->flash('error', 'Scan at least one child EPC before packing.');

            return;
        }

        if ($this->parentLabelId !== null) {
            $this->performContinuePack($attach, $custodyGate, $shippable);

            return;
        }

        $childIds = $this->childIds();

        $siteId = $this->lockedCommissionSiteId ?? CurrentSite::preferredId(
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

        $locks = app(AcquirePackChildLocks::class)->acquire($childIds);

        if ($locks === null) {
            $this->flash('error', 'Another pack is in progress for one of these children. Try again in a moment.');

            return;
        }

        try {
            // Re-check under the locks: another operator may have claimed these children,
            // or a hold may have been raised, between the scan and this confirmation.
            $custodyGate->assertOperableFor($childIds, 'packing');

            $onHandError = $this->assertChildrenOnHand($childIds, $siteId, $shippable);
            if ($onHandError !== null) {
                $this->flash('error', $onHandError);

                return;
            }

            $conflicts = [
                ...$this->openParentConflictsForChildIds($childIds),
                ...$this->existingLabelConflictsForChildIds($childIds, $this->parentLabelId),
            ];

            if ($conflicts !== []) {
                $this->flash('error', implode(' ', $conflicts).' Use Break & pack.');

                return;
            }

            $childUrns = $this->resolveChildUrnsFromDb($childIds);
            if ($childUrns === null) {
                return;
            }

            $batch = $generate->execute([
                'allocation_mode' => SsccAllocationMode::Sequential->value,
                'label_count' => 1,
                'copies_per_label' => 1,
                'enforce_forward_only' => true,
                'site_id' => $siteId,
                'child_epcs' => implode("\n", $childUrns),
                'emit_epcis' => true,
                'epcis_sync' => true,
                'emit_disaggregation' => false,
                'send_to_printer' => false,
            ]);
        } catch (InvalidArgumentException|Throwable $exception) {
            $this->flash('error', $exception->getMessage());

            Notification::make()
                ->title('Pack failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            if ($locks !== null) {
                app(AcquirePackChildLocks::class)->release($locks);
            }

            app(AcquirePackChildLocks::class)->releaseSoftReserves($childIds);
        }

        $batch->refresh(['labels.children']);
        $this->batchUrl = SsccLabelResource::getUrl('view-batch', ['record' => $batch]);

        // An uncommissioned parent or missing aggregation cannot ship — keep the pack list
        // so the operator can retry rather than losing scans to a batch that only looks complete.
        if (! $batch->packingSucceeded()) {
            $detail = implode(' ', $batch->errorLines());
            $this->flash('error', 'Batch #'.$batch->getKey().' was not fully packed. '.$detail);

            Notification::make()
                ->title('Pack incomplete')
                ->body('SSCC batch #'.$batch->getKey().' was not fully packed. '.$detail)
                ->danger()
                ->send();

            return;
        }

        $this->children = [];
        $this->lockedCommissionSiteId = null;

        if ($batch->hasErrors()) {
            $detail = implode(' ', $batch->errorLines());
            $this->flash('warn', 'Created SSCC batch #'.$batch->getKey().' with warnings. '.$detail);

            Notification::make()
                ->title('Pack completed with warnings')
                ->body('SSCC batch #'.$batch->getKey().'. '.$detail)
                ->warning()
                ->send();
        } else {
            $this->flash('ok', 'Created SSCC batch #'.$batch->getKey().'.');

            Notification::make()
                ->title('Pack complete')
                ->body('SSCC batch #'.$batch->getKey().' ready.')
                ->success()
                ->send();
        }

        $this->redirect($this->batchUrl);
    }

    private function performContinuePack(
        AttachChildrenToExistingSscc $attach,
        EpcCustodyGate $custodyGate,
        ShippableEpcsAtSite $shippable,
    ): void {
        $childIds = $this->childIds();
        $siteId = $this->lockedCommissionSiteId ?? CurrentSite::preferredId(
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

        $label = SsccLabel::query()->with('batch')->find($this->parentLabelId);
        if (! $label instanceof SsccLabel) {
            $this->flash('error', 'Bound SSCC label is no longer available. Clear and scan it again.');

            return;
        }

        $parentEpcId = $this->boundParentEpc()?->getKey();
        $locks = app(AcquirePackChildLocks::class)->acquireForPack(
            $childIds,
            $parentEpcId !== null ? (int) $parentEpcId : null,
        );

        if ($locks === null) {
            $this->flash('error', 'Another pack is in progress for one of these children. Try again in a moment.');

            return;
        }

        try {
            $custodyGate->assertOperableFor($childIds, 'packing');

            $onHandError = $this->assertChildrenOnHand($childIds, $siteId, $shippable);
            if ($onHandError !== null) {
                $this->flash('error', $onHandError);

                return;
            }

            $conflicts = [
                ...$this->openParentConflictsForChildIds($childIds),
                ...$this->existingLabelConflictsForChildIds($childIds, $this->parentLabelId),
            ];

            if ($conflicts !== []) {
                $this->flash('error', implode(' ', $conflicts).' Unpack it first, then pack here.');

                return;
            }

            $childUrns = $this->resolveChildUrnsFromDb($childIds);
            if ($childUrns === null) {
                return;
            }

            $batch = $attach->execute($label, $childUrns, [
                'site_id' => $siteId,
                'epcis_sync' => true,
            ]);
        } catch (InvalidArgumentException|Throwable $exception) {
            $this->flash('error', $exception->getMessage());

            Notification::make()
                ->title('Pack failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            if ($locks !== null) {
                app(AcquirePackChildLocks::class)->release($locks);
            }

            app(AcquirePackChildLocks::class)->releaseSoftReserves($childIds);
        }

        $batch->refresh(['labels.children']);
        $this->batchUrl = SsccLabelResource::getUrl('view-batch', ['record' => $batch]);

        if (! $batch->packingSucceeded()) {
            $detail = implode(' ', $batch->errorLines());
            $this->flash('error', 'Batch #'.$batch->getKey().' was not fully packed. '.$detail);

            Notification::make()
                ->title('Pack incomplete')
                ->body('SSCC batch #'.$batch->getKey().' was not fully packed. '.$detail)
                ->danger()
                ->send();

            return;
        }

        $this->children = [];

        if ($batch->hasErrors()) {
            $detail = implode(' ', $batch->errorLines());
            $this->flash('warn', 'Added children to SSCC '.$this->parentSscc18.'. '.$detail);

            Notification::make()
                ->title('Pack completed with warnings')
                ->body('SSCC '.$this->parentSscc18.'. '.$detail)
                ->warning()
                ->send();
        } else {
            $this->flash('ok', 'Added children to SSCC '.$this->parentSscc18.'. Scan more or ship this SSCC.');

            Notification::make()
                ->title('Pack complete')
                ->body('SSCC '.$this->parentSscc18.' updated.')
                ->success()
                ->send();
        }
    }

    private function packConfirmationDescription(): string
    {
        $settings = TenantSsccSettings::resolve();
        $prefix = (string) ($settings['company_prefix'] ?? '');
        $extension = (int) ($settings['extension_digit'] ?? 0);
        $childCount = count($this->children);
        $siteName = $this->commissionSiteName();

        if ($this->parentLabelId !== null) {
            $summary = $this->packContentSummary();
            $lines = [
                'Organization: '.$this->tenantNameDisplay(),
                'GS1 Company Prefix: '.($prefix !== '' ? $prefix : '(not configured)'),
                'Parent SSCC: '.($this->parentSscc18 ?? ''),
                'Commission site: '.($siteName ?? '(select a site)'),
                'Already on this SSCC: '.$this->boundParentChildCount(),
                "New children to add: {$childCount}",
            ];

            if ($summary['is_mixed']) {
                $lines[] = 'Mixed logistics unit — SSCC only ('.$summary['lot_count'].' lots, '.$summary['gtin_count'].' GTINs).';
            }

            return implode("\n", $lines);
        }

        $lines = [
            'Organization: '.$this->tenantNameDisplay(),
            'GS1 Company Prefix: '.($prefix !== '' ? $prefix : '(not configured)'),
            'Extension digit: '.$extension,
            'Commission site: '.($siteName ?? '(select a site)'),
            "Children to pack: {$childCount}",
            'New parent labels: 1',
        ];

        try {
            $siteId = CurrentSite::preferredId(
                null,
                EligibleReceiveSites::organizationOptions(),
            );
            $previews = app(PreviewNextSsccLabels::class)->handle(1, siteId: $siteId ?? $this->lockedCommissionSiteId);
            $previewText = implode(', ', $previews);
            $lines[] = "Next parent SSCC: {$previewText}";
        } catch (InvalidArgumentException|Throwable $exception) {
            $lines[] = 'Next parent SSCC: '.$exception->getMessage();
        }

        $lines[] = 'New parents use your organization company prefix (not the manufacturer of the children).';

        return implode("\n", $lines);
    }

    private function commissionSiteName(): ?string
    {
        return $this->commissionSite()?->name;
    }

    private function commissionSite(): ?Site
    {
        if ($this->lockedCommissionSiteId !== null) {
            return Site::query()->find($this->lockedCommissionSiteId);
        }

        $siteId = CurrentSite::preferredId(
            null,
            EligibleReceiveSites::organizationOptions(),
        );

        return $siteId !== null ? Site::query()->find($siteId) : null;
    }

    /**
     * @param  list<int>  $childIds
     */
    private function assertChildrenOnHand(array $childIds, int $siteId, ShippableEpcsAtSite $shippable): ?string
    {
        foreach ($childIds as $childId) {
            if (! $shippable->contains($siteId, $childId)) {
                return 'An EPC is no longer on hand at the selected site. Remove it and rescan.';
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

    /**
     * @return list<int>
     */
    private function childIds(): array
    {
        return array_values(array_map(
            fn (array $row): int => (int) $row['epc_id'],
            $this->children,
        ));
    }

    /**
     * @param  list<int>  $childIds
     * @return list<string>
     */
    private function resolveChildUrnsFromDb(array $childIds): ?array
    {
        $urnsById = Epc::query()
            ->whereIn('id', $childIds)
            ->pluck('epc_uri', 'id');

        if ($urnsById->count() !== count($childIds)) {
            $this->flash('error', 'Could not resolve all child EPCs from the database.');

            return null;
        }

        $ordered = [];
        foreach ($childIds as $childId) {
            $uri = (string) ($urnsById[$childId] ?? '');
            if ($uri === '') {
                $this->flash('error', 'Could not resolve all child EPCs from the database.');

                return null;
            }

            $ordered[] = $uri;
        }

        return $ordered;
    }

    private function openParentLinkForChild(int $childEpcId): ?AggregationLink
    {
        return AggregationLink::query()
            ->open()
            ->where('child_epc_id', $childEpcId)
            ->with('parentEpc')
            ->first();
    }

    /**
     * @param  list<int>  $childIds
     * @return list<string>
     */
    private function openParentConflictsForChildIds(array $childIds): array
    {
        $conflicts = [];

        foreach ($childIds as $childId) {
            $link = $this->openParentLinkForChild($childId);
            if ($link === null || $this->openLinkIsBoundParent($link)) {
                continue;
            }

            $parentLabel = $this->parentLabelForLink($link);
            $child = Epc::query()->find($childId);
            $childLabel = $child instanceof Epc ? $this->epcLabel($child) : (string) $childId;
            $conflicts[] = "{$childLabel} is already packed under {$parentLabel}.";
        }

        return $conflicts;
    }

    private function parentLabelForLink(AggregationLink $link): string
    {
        $parent = $link->parentEpc;

        return $parent instanceof Epc ? $this->epcLabel($parent) : 'another parent';
    }

    /**
     * Children already attached to a live (non-failed) SSCC label whose hierarchy was never
     * broken. Aggregation links alone miss labels whose aggregation EPCIS has not been ingested
     * yet; a closed link means the child was unpacked, so it stays packable.
     *
     * @param  list<int>  $childIds
     * @return list<string>
     */
    private function existingLabelConflictsForChildIds(array $childIds, ?int $exceptLabelId = null): array
    {
        if ($childIds === []) {
            return [];
        }

        $labelsByUrn = [];

        foreach (Epc::query()->whereIn('id', $childIds)->with('ilmd')->get(['id', 'epc_uri', 'sscc18', 'ai_01_21', 'gtin14', 'serial_number', 'epc_type']) as $epc) {
            $uri = (string) $epc->epc_uri;

            if ($uri !== '') {
                $labelsByUrn[$uri] = $this->epcLabel($epc);
            }
        }

        if ($labelsByUrn === []) {
            return [];
        }

        $rows = SsccLabelChild::query()
            ->join('sscc_labels', 'sscc_labels.id', '=', 'sscc_label_children.sscc_label_id')
            ->leftJoin('sscc_label_batches', 'sscc_label_batches.id', '=', 'sscc_labels.batch_id')
            ->leftJoin('epcs as parent_epcs', 'parent_epcs.epc_uri', '=', 'sscc_labels.sscc_urn')
            ->leftJoin('epcs as child_epcs', 'child_epcs.epc_uri', '=', 'sscc_label_children.child_epc')
            ->whereIn('sscc_label_children.child_epc', array_keys($labelsByUrn))
            ->when($exceptLabelId !== null, function ($query) use ($exceptLabelId): void {
                $query->where('sscc_labels.id', '!=', $exceptLabelId);
            })
            ->where(function ($query): void {
                $query->whereNull('sscc_label_batches.status')
                    ->orWhere('sscc_label_batches.status', '!=', SsccLabelBatchStatus::Failed->value);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('aggregation_links')
                    ->whereColumn('aggregation_links.parent_epc_id', 'parent_epcs.id')
                    ->whereColumn('aggregation_links.child_epc_id', 'child_epcs.id')
                    ->whereNotNull('aggregation_links.valid_to');
            })
            ->orderBy('sscc_labels.id')
            ->get([
                'sscc_label_children.child_epc as child_epc',
                'sscc_labels.sscc_18 as parent_sscc',
            ]);

        $conflicts = [];

        foreach ($rows as $row) {
            $childUrn = (string) $row->child_epc;
            $childLabel = $labelsByUrn[$childUrn] ?? $childUrn;
            $conflicts[$childUrn] = "{$childLabel} is already on SSCC label {$row->parent_sscc}.";
        }

        return array_values($conflicts);
    }

    public function boundParentChildCount(): int
    {
        if ($this->parentLabelId === null) {
            return 0;
        }

        return SsccLabelChild::query()
            ->where('sscc_label_id', $this->parentLabelId)
            ->count();
    }

    /**
     * @return array{lot_count: int, gtin_count: int, is_mixed: bool}
     */
    public function packContentSummary(): array
    {
        $urns = [];
        $ids = $this->childIds();

        if ($this->parentLabelId !== null) {
            $urns = SsccLabelChild::query()
                ->where('sscc_label_id', $this->parentLabelId)
                ->pluck('child_epc')
                ->filter()
                ->map(fn (mixed $urn): string => (string) $urn)
                ->all();
        }

        $query = Epc::query()->with('ilmd');
        $query->where(function ($match) use ($ids, $urns): void {
            if ($ids !== []) {
                $match->orWhereIn('id', $ids);
            }

            if ($urns !== []) {
                $match->orWhereIn('epc_uri', $urns);
            }
        });

        if ($ids === [] && $urns === []) {
            return ['lot_count' => 0, 'gtin_count' => 0, 'is_mixed' => false];
        }

        $epcs = $query->get(['id', 'epc_uri', 'gtin14']);
        $lots = [];
        $gtins = [];

        foreach ($epcs as $epc) {
            $gtin = trim((string) $epc->gtin14);
            if ($gtin !== '') {
                $gtins[$gtin] = true;
            }

            $lot = trim((string) ($epc->ilmd?->lot_number ?? ''));
            if ($lot !== '') {
                $lots[$lot] = true;
            }
        }

        $lotCount = count($lots);
        $gtinCount = count($gtins);

        return [
            'lot_count' => $lotCount,
            'gtin_count' => $gtinCount,
            'is_mixed' => $lotCount > 1 || $gtinCount > 1,
        ];
    }

    public function isMixedLogisticsUnit(): bool
    {
        return $this->packContentSummary()['is_mixed'];
    }

    private function handleIssuedSsccScan(SsccLabel $label, EpcCustodyGate $custodyGate): void
    {
        foreach ($this->children as $row) {
            if ($this->childRowMatchesLabel($row, $label)) {
                $this->flash('error', 'This SSCC is already in the pack list as a child. Remove it before using it as the parent.');
                $this->scan = '';
                $this->dispatch('scan-result', tone: 'error');

                return;
            }
        }

        if ($this->parentLabelId !== null && (int) $this->parentLabelId === (int) $label->getKey()) {
            $this->flash('ok', 'Already bound to SSCC '.$label->sscc_18.'.');
            $this->scan = '';
            $this->dispatch('scan-result', tone: 'ok');

            return;
        }

        if ($this->parentLabelId !== null) {
            $this->flash('error', 'Wrong parent. Clear the pack list to bind a different SSCC, or scan a child unit.');
            $this->scan = '';
            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        $rejection = $this->bindRejectionForLabel($label, $custodyGate);
        if ($rejection !== null) {
            $this->flash('error', $rejection);
            $this->scan = '';
            $this->dispatch('scan-result', tone: 'error');

            return;
        }

        $parentEpc = $this->parentEpcForLabel($label);
        if ($parentEpc instanceof Epc && ! app(AcquirePackChildLocks::class)->softReserve((int) $parentEpc->getKey())) {
            $this->flash('warn', 'Another operator is packing this SSCC. Try again shortly.');
            $this->scan = '';
            $this->dispatch('scan-result', tone: 'warn');

            return;
        }

        $this->parentLabelId = (int) $label->getKey();
        $this->parentSscc18 = (string) $label->sscc_18;
        $this->parentUrn = (string) $label->sscc_urn;
        $this->scan = '';
        $this->flash('ok', 'Bound parent SSCC '.$label->sscc_18.'. Scan children to add.');
        $this->dispatch('scan-result', tone: 'ok');
    }

    /**
     * @param  array{epc_id: int, label: string}  $row
     */
    private function childRowMatchesLabel(array $row, SsccLabel $label): bool
    {
        $child = Epc::query()->find((int) $row['epc_id']);
        if (! $child instanceof Epc) {
            return false;
        }

        $urn = trim((string) $label->sscc_urn);
        $sscc18 = trim((string) $label->sscc_18);

        return ($urn !== '' && (string) $child->epc_uri === $urn)
            || ($sscc18 !== '' && (string) $child->sscc18 === $sscc18);
    }

    private function bindRejectionForLabel(SsccLabel $label, EpcCustodyGate $custodyGate): ?string
    {
        $tenantPrefix = (string) (TenantSsccSettings::resolve()['company_prefix'] ?? '');
        if ($tenantPrefix === '' || (string) $label->company_prefix !== $tenantPrefix) {
            return 'This SSCC was not issued under this organization company prefix.';
        }

        $label->loadMissing('batch');
        $batch = $label->batch;
        if ($batch !== null && $batch->status === SsccLabelBatchStatus::Failed) {
            return 'This SSCC batch failed. Generate a new label.';
        }

        $site = $this->commissionSite();
        $batchSiteId = $batch?->commission_site_id;
        if ($site !== null && $batchSiteId !== null && (int) $batchSiteId !== (int) $site->getKey()) {
            return 'This SSCC was commissioned at a different site.';
        }

        $parentEpc = $this->parentEpcForLabel($label);
        if ($parentEpc instanceof Epc) {
            try {
                $custodyGate->assertOperableFor($parentEpc, 'packing');
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
        }

        return null;
    }

    private function issuedLabelForScan(string $scan, ResolveEpcFromScan $resolveEpcFromScan): ?SsccLabel
    {
        $sscc = ElementString::ssccIdentity($scan);
        if ($sscc !== null) {
            $byDigits = SsccLabel::query()->where('sscc_18', $sscc['sscc18'])->first();
            if ($byDigits instanceof SsccLabel) {
                return $byDigits;
            }
        }

        if (str_starts_with($scan, 'urn:epc:id:sscc:')) {
            $byUrn = SsccLabel::query()->where('sscc_urn', $scan)->first();
            if ($byUrn instanceof SsccLabel) {
                return $byUrn;
            }
        }

        $resolved = $resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'] ?? null;
        if ($epc instanceof Epc && (($epc->epc_type ?? null) === 'sscc' || filled($epc->sscc18))) {
            return $this->parentLabelForEpc($epc);
        }

        return null;
    }

    private function parentLabelForEpc(Epc $epc): ?SsccLabel
    {
        return SsccLabel::query()
            ->where(function ($query) use ($epc): void {
                $urn = trim((string) $epc->epc_uri);
                $sscc18 = trim((string) $epc->sscc18);

                if ($urn !== '') {
                    $query->orWhere('sscc_urn', $urn);
                }

                if ($sscc18 !== '') {
                    $query->orWhere('sscc_18', $sscc18);
                }
            })
            ->first();
    }

    private function parentEpcForLabel(SsccLabel $label): ?Epc
    {
        $urn = trim((string) $label->sscc_urn);
        if ($urn !== '') {
            $byUrn = Epc::query()->where('epc_uri', $urn)->first();
            if ($byUrn instanceof Epc) {
                return $byUrn;
            }
        }

        $sscc18 = trim((string) $label->sscc_18);
        if ($sscc18 !== '') {
            return Epc::query()->where('sscc18', $sscc18)->first();
        }

        return null;
    }

    private function boundParentEpc(): ?Epc
    {
        if ($this->parentLabelId === null) {
            return null;
        }

        $label = SsccLabel::query()->find($this->parentLabelId);

        return $label instanceof SsccLabel ? $this->parentEpcForLabel($label) : null;
    }

    private function childAlreadyOnBoundParent(Epc $epc): bool
    {
        if ($this->parentLabelId === null) {
            return false;
        }

        $openLink = $this->openParentLinkForChild((int) $epc->getKey());
        if ($openLink !== null && $this->openLinkIsBoundParent($openLink)) {
            return true;
        }

        $uri = trim((string) $epc->epc_uri);
        if ($uri === '') {
            return false;
        }

        return SsccLabelChild::query()
            ->where('sscc_label_id', $this->parentLabelId)
            ->where('child_epc', $uri)
            ->exists();
    }

    private function openLinkIsBoundParent(AggregationLink $link): bool
    {
        if ($this->parentUrn === null || $this->parentUrn === '') {
            return false;
        }

        $parent = $link->parentEpc;

        return $parent instanceof Epc && (string) $parent->epc_uri === $this->parentUrn;
    }

    private function releaseBoundParentSoftReserve(): void
    {
        $parentEpc = $this->boundParentEpc();
        if ($parentEpc instanceof Epc) {
            app(AcquirePackChildLocks::class)->releaseSoftReserve((int) $parentEpc->getKey());
        }
    }

    private function flash(string $tone, string $message): void
    {
        $this->lastTone = $tone;
        $this->lastMessage = $message;
    }
}

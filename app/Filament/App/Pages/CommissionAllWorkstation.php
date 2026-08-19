<?php

namespace App\Filament\App\Pages;

use App\Actions\Disposition\EmitCommissioningEpcisForEpcs;
use App\Actions\Epcis\ResolveEpcFromScan;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\Epc;
use App\Models\Site;
use App\Models\User;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\SiteAccess;
use App\Support\Epcis\EpcHasCommissioningEvent;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
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

class CommissionAllWorkstation extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static ?string $navigationLabel = 'Commission-all';

    protected static ?string $title = 'Commission-all';

    protected static ?int $navigationSort = 16;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.commission-all-workstation';

    public string $scan = '';

    /** @var list<array{epc_id: int, label: string}> */
    #[Locked]
    public array $confirmed = [];

    public ?string $lastMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastTone = null;

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsCommissioning())
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Scan on-hand EPCs that need commissioning ObjectEvents, then author commissioning EPCIS at the selected site.';
    }

    public function processScan(
        ResolveEpcFromScan $resolveEpcFromScan,
        EpcHasCommissioningEvent $hasCommissioningEvent,
        ShippableEpcsAtSite $shippable,
        ReceivingGate $receivingGate,
    ): void {
        $scan = ElementString::normalize(trim($this->scan));
        $this->scan = $scan;

        if ($scan === '') {
            $this->flash('error', 'Scan an SSCC or SGTIN to commission.');
            $this->dispatch('focus-scan');

            return;
        }

        $site = $this->selectedSite();
        if ($site === null) {
            $this->flash('error', 'Select a site (site chooser) before scanning.');
            $this->dispatch('focus-scan');

            return;
        }

        if (! $this->assertSiteAccess((int) $site->getKey())) {
            $this->dispatch('focus-scan');

            return;
        }

        $resolved = $resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'] ?? null;

        if (! $epc instanceof Epc || blank($epc->epc_uri)) {
            $this->flash('error', 'No EPC found for that scan.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        $epcId = (int) $epc->getKey();

        foreach ($this->confirmed as $row) {
            if ((int) $row['epc_id'] === $epcId) {
                $this->flash('warn', 'Already in the commission list.');
                $this->scan = '';
                $this->dispatch('focus-scan');

                return;
            }
        }

        if (! $shippable->contains((int) $site->getKey(), $epcId)) {
            $this->flash('error', 'Not on hand at the selected site.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        if ($receivingGate->epcBlockedByOpenHold($epc) !== null) {
            $this->flash('error', 'Quarantined — cannot commission while under an open hold.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        if ($hasCommissioningEvent->for($epcId)) {
            $this->flash('warn', 'Already has a commissioning ObjectEvent — skipped.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        $this->confirmed[] = [
            'epc_id' => $epcId,
            'label' => $this->epcLabel($epc),
        ];

        $this->scan = '';
        $this->flash('ok', 'Added '.$this->epcLabel($epc));
        $this->dispatch('focus-scan');
    }

    public function removeConfirmed(int $epcId): void
    {
        $this->confirmed = array_values(array_filter(
            $this->confirmed,
            fn (array $row): bool => (int) $row['epc_id'] !== $epcId,
        ));
    }

    public function clearConfirmed(): void
    {
        $this->confirmed = [];
        $this->flash('ok', 'Cleared list.');
        $this->dispatch('focus-scan');
    }

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                Action::make('confirmCommission')
                    ->label('Complete commission-all')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->disabled(fn (): bool => $this->confirmed === [])
                    ->modalHeading('Commission selected EPCs?')
                    ->modalDescription(function (): string {
                        $count = count($this->confirmed);
                        $siteName = $this->selectedSite()?->name ?? '(select a site)';

                        return "Author commissioning ObjectEvents for {$count} EPC(s) at {$siteName}?";
                    })
                    ->modalSubmitActionLabel('Commission')
                    ->action(function (
                        EmitCommissioningEpcisForEpcs $emit,
                        ShippableEpcsAtSite $shippable,
                        ReceivingGate $receivingGate,
                    ): void {
                        if ($this->confirmed === []) {
                            $this->flash('error', 'Scan at least one EPC before completing.');

                            return;
                        }

                        $site = $this->selectedSite();
                        if ($site === null) {
                            $this->flash('error', 'Select a site (site chooser) before completing.');

                            return;
                        }

                        $siteId = (int) $site->getKey();
                        if (! $this->assertSiteAccess($siteId)) {
                            return;
                        }

                        $epcIds = array_map(fn (array $row): int => (int) $row['epc_id'], $this->confirmed);
                        $eligibilityError = $this->assertConfirmedStillEligible($epcIds, $siteId, $shippable, $receivingGate);
                        if ($eligibilityError !== null) {
                            $this->flash('error', $eligibilityError);
                            Notification::make()
                                ->title('Commission-all failed')
                                ->body($eligibilityError)
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $result = $emit->handle($epcIds, $siteId, [
                                'sync' => true,
                                'dispatch' => true,
                            ]);
                        } catch (InvalidArgumentException|Throwable $exception) {
                            $this->flash('error', $exception->getMessage());
                            Notification::make()
                                ->title('Commission-all failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $count = (int) ($result['commissioned_count'] ?? 0);
                        $skipped = (int) ($result['skipped_count'] ?? 0);
                        $message = "Commissioned {$count} EPC".($count === 1 ? '' : 's').'.';
                        if ($skipped > 0) {
                            $message .= " Skipped {$skipped} already commissioned.";
                        }

                        $this->confirmed = [];
                        $this->flash($count > 0 ? 'ok' : 'warn', $message);
                        Notification::make()
                            ->title($count > 0 ? 'Commission-all complete' : 'Nothing commissioned')
                            ->body($message)
                            ->success()
                            ->send();
                        $this->dispatch('focus-scan');
                    }),
                'commission_all_workstation_complete',
                requireReason: true,
                subject: fn (): ?Site => $this->selectedSite(),
            ),
        ];
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function assertConfirmedStillEligible(
        array $epcIds,
        int $siteId,
        ShippableEpcsAtSite $shippable,
        ReceivingGate $receivingGate,
    ): ?string {
        foreach ($epcIds as $epcId) {
            if (! $shippable->contains($siteId, $epcId)) {
                return 'An EPC is no longer on hand at the selected site. Remove it and rescan.';
            }

            $epc = Epc::query()->find($epcId);
            if (! $epc instanceof Epc) {
                return 'An EPC is missing. Clear the list and rescan.';
            }

            if ($receivingGate->epcBlockedByOpenHold($epc) !== null) {
                return 'An EPC is quarantined and cannot be commissioned.';
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

    private function selectedSite(): ?Site
    {
        $siteId = CurrentSite::preferredId(
            null,
            EligibleReceiveSites::organizationOptions(),
        );

        return $siteId !== null ? Site::query()->find($siteId) : null;
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

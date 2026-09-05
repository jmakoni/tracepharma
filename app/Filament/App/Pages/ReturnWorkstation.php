<?php

namespace App\Filament\App\Pages;

use App\Actions\Disposition\EmitReturningEpcis;
use App\Actions\Epcis\ResolveEpcFromScan;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Epcis\Epc;
use App\Models\Site;
use App\Models\User;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;
use UnitEnum;

class ReturnWorkstation extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $navigationLabel = 'Return';

    protected static ?string $title = 'Return';

    protected static ?int $navigationSort = 18;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.return-workstation';

    public string $scan = '';

    /**
     * Locked to the site of the first confirmed scan for the session list.
     */
    #[Locked]
    public ?int $siteId = null;

    /** @var list<array{epc_id: int, label: string}> */
    #[Locked]
    public array $confirmed = [];

    public ?string $lastMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastTone = null;

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsReturning()
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Scan on-hand EPCs to author returning ObjectEvents (disposition returned) at the locked site.';
    }

    public function processScan(
        ResolveEpcFromScan $resolveEpcFromScan,
        ReceivingGate $receivingGate,
        EpcCustodyGate $custodyGate,
        ShippableEpcsAtSite $shippable,
        EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
    ): void {
        $scan = ElementString::normalize(trim($this->scan));
        $this->scan = $scan;

        if ($scan === '') {
            $this->flash('error', 'Scan an SSCC or SGTIN to return.');
            $this->dispatch('focus-scan');

            return;
        }

        if (! $this->assertCurrentSiteMatchesLock()) {
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
                $this->flash('warn', 'Already in the return list.');
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

        $hold = $receivingGate->epcBlockedByOpenHold($epc);
        if ($hold !== null) {
            $this->flash('error', 'Quarantined — cannot return while under an open hold.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        if ($epcOnAnotherOpenReceivingSession->existsOnAnyExclusiveSession($epc)) {
            $this->flash('error', 'Already confirmed on an open receive session.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        try {
            $custodyGate->assertInCustody($epc, 'returning');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        if ($this->siteId === null) {
            $this->siteId = (int) $site->getKey();
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

        if ($this->confirmed === []) {
            $this->siteId = null;
        }
    }

    public function clearConfirmed(): void
    {
        $this->confirmed = [];
        $this->siteId = null;
        $this->flash('ok', 'Cleared list.');
        $this->dispatch('focus-scan');
    }

    public function lockedSiteName(): ?string
    {
        if ($this->siteId === null) {
            return null;
        }

        $name = Site::query()->whereKey($this->siteId)->value('name');

        return filled($name) ? (string) $name : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                Action::make('confirmReturn')
                    ->label('Complete return')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->disabled(fn (): bool => $this->confirmed === [])
                    ->modalHeading('Return selected EPCs?')
                    ->modalDescription(function (): string {
                        $count = count($this->confirmed);
                        $siteName = $this->selectedSite()?->name ?? '(select a site)';

                        return "Author returning ObjectEvents for {$count} EPC(s) at {$siteName}? Disposition will be returned.";
                    })
                    ->modalSubmitActionLabel('Return')
                    ->action(function (
                        EmitReturningEpcis $emit,
                        ShippableEpcsAtSite $shippable,
                        ReceivingGate $receivingGate,
                    ): void {
                        if ($this->confirmed === []) {
                            $this->flash('error', 'Scan at least one EPC before completing.');

                            return;
                        }

                        if (! $this->assertCurrentSiteMatchesLock()) {
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
                        $eligibilityError = $this->assertConfirmedStillEligible(
                            $epcIds,
                            $siteId,
                            $shippable,
                            $receivingGate,
                            app(EpcCustodyGate::class),
                            app(EpcOnAnotherOpenReceivingSession::class),
                        );
                        if ($eligibilityError !== null) {
                            $this->flash('error', $eligibilityError);
                            Notification::make()
                                ->title('Return failed')
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
                                ->title('Return failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $count = (int) ($result['returned_count'] ?? 0);
                        $message = "Returned {$count} EPC".($count === 1 ? '' : 's').'.';

                        $this->confirmed = [];
                        $this->siteId = null;
                        $this->flash($count > 0 ? 'ok' : 'warn', $message);
                        Notification::make()
                            ->title($count > 0 ? 'Return complete' : 'Nothing returned')
                            ->body($message)
                            ->success()
                            ->send();
                        $this->dispatch('focus-scan');
                    }),
                'return_workstation_complete',
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
        EpcCustodyGate $custodyGate,
        EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
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
                return 'An EPC is quarantined and cannot be returned.';
            }

            if ($epcOnAnotherOpenReceivingSession->existsOnAnyExclusiveSession($epc)) {
                return 'An EPC is already confirmed on an open receive session.';
            }

            try {
                $custodyGate->assertInCustody($epc, 'returning');
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
        }

        return null;
    }

    private function assertCurrentSiteMatchesLock(): bool
    {
        if ($this->siteId === null) {
            return true;
        }

        $currentId = CurrentSite::preferredId(
            null,
            EligibleReceiveSites::organizationOptions(),
        );

        if ($currentId === null || (int) $currentId !== $this->siteId) {
            $lockedName = $this->lockedSiteName() ?? ('#'.$this->siteId);
            $this->flash(
                'error',
                "Site changed mid-session. Switch back to {$lockedName} or clear the list.",
            );

            return false;
        }

        return true;
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
        if ($this->siteId !== null) {
            return Site::query()->find($this->siteId);
        }

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

    public static function getDocumentation(): array|string
    {
        return 'workflows.return';
    }
}

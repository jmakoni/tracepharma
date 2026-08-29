<?php

namespace App\Filament\App\Pages;

use App\Actions\Disposition\EmitDecommissioningEpcis;
use App\Actions\Epcis\ResolveEpcFromScan;
use App\Enums\DecommissionReason;
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
use App\Support\Disposition\AssertDecommissionMassApproval;
use App\Support\Gs1\ElementString;
use App\Support\Gs1\EpcBarcodeDisplay;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\EpcOnOpenShippingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantFeatures;
use App\Support\Transferring\EpcOnOpenTransferringSession;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;
use UnitEnum;

class DecommissionWorkstation extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?string $navigationLabel = 'Decommission';

    protected static ?string $title = 'Decommission';

    protected static ?int $navigationSort = 17;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected string $view = 'filament.app.pages.decommission-workstation';

    public string $scan = '';

    /** @var list<array{epc_id: int, label: string}> */
    #[Locked]
    public array $confirmed = [];

    public ?string $lastMessage = null;

    /** @var 'ok'|'warn'|'error'|null */
    public ?string $lastTone = null;

    public ?string $decommissionReason = null;

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsCommissioning()
            && JobRoleAccess::allows(Permissions::NavShip);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Scan on-hand EPCs at the selected site, choose a reason, then author decommissioning ObjectEvents.';
    }

    public function processScan(
        ResolveEpcFromScan $resolveEpcFromScan,
        EpcCustodyGate $custodyGate,
        ShippableEpcsAtSite $shippable,
        ReceivingGate $receivingGate,
        EpcOnOpenShippingSession $epcOnOpenShippingSession,
        EpcOnOpenTransferringSession $epcOnOpenTransferringSession,
        EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
    ): void {
        $scan = ElementString::normalize(trim($this->scan));
        $this->scan = $scan;

        if ($scan === '') {
            $this->flash('error', 'Scan an SSCC or SGTIN to decommission.');
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
                $this->flash('warn', 'Already in the decommission list.');
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
            $this->flash('error', 'Quarantined — cannot decommission while under an open hold.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        if ($epcOnOpenShippingSession->exists($epc)) {
            $this->flash('error', 'Already confirmed on an open ship order.');
            $this->scan = '';
            $this->dispatch('focus-scan');

            return;
        }

        if ($epcOnOpenTransferringSession->exists($epc)) {
            $this->flash('error', 'Already confirmed on an open or in-transit transfer.');
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
            $custodyGate->assertOperableFor($epc, 'decommissioning');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
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
                Action::make('confirmDecommission')
                    ->label('Complete decommission')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->disabled(fn (): bool => $this->confirmed === [])
                    ->modalHeading('Decommission selected EPCs?')
                    ->form(function (): array {
                        $count = count($this->confirmed);
                        $mass = app(AssertDecommissionMassApproval::class);
                        $siteId = $this->selectedSite()?->getKey();
                        $siteId = $siteId !== null ? (int) $siteId : null;
                        $threshold = $mass->threshold();
                        $effective = $mass->effectiveCount($count, $siteId);
                        $recent = $mass->recentDecommissionedEpcCount($siteId);
                        $fields = [
                            Select::make('reason')
                                ->label('Decommission reason')
                                ->options(DecommissionReason::options())
                                ->required()
                                ->native(false)
                                ->live()
                                ->helperText(function (?string $state): ?string {
                                    $reason = DecommissionReason::tryFromMixed($state);
                                    if ($reason === null) {
                                        return null;
                                    }

                                    return 'CBV disposition: '.$reason->dispositionUri();
                                }),
                        ];

                        if ($mass->requiresSecondApprover($count, $siteId)) {
                            $fields[] = TextInput::make('approver_email')
                                ->label('Second approver email')
                                ->email()
                                ->required()
                                ->helperText(
                                    "Mass decommission (effective {$effective} = {$count} this batch + {$recent} recent at site > {$threshold}) requires a different authorized approver."
                                );
                            $fields[] = TextInput::make('approver_password')
                                ->label('Second approver password')
                                ->password()
                                ->required()
                                ->revealable();
                        }

                        return $fields;
                    })
                    ->modalDescription(function (): string {
                        $count = count($this->confirmed);
                        $site = $this->selectedSite();
                        $siteName = $site?->name ?? '(select a site)';
                        $mass = app(AssertDecommissionMassApproval::class);
                        $siteId = $site !== null ? (int) $site->getKey() : null;
                        $threshold = $mass->threshold();
                        $massNote = $mass->requiresSecondApprover($count, $siteId)
                            ? " Mass decommission requires a second approver (effective {$mass->effectiveCount($count, $siteId)} > threshold {$threshold})."
                            : '';

                        return "Author decommissioning ObjectEvents for {$count} EPC(s) at {$siteName}?{$massNote}";
                    })
                    ->modalSubmitActionLabel('Decommission')
                    ->action(function (
                        array $data,
                        EmitDecommissioningEpcis $emit,
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

                        $reason = DecommissionReason::tryFromMixed($data['reason'] ?? null);
                        if ($reason === null) {
                            $this->flash('error', 'Select a decommission reason.');

                            return;
                        }

                        $this->decommissionReason = $reason->value;

                        $approverUserId = null;
                        $count = count($this->confirmed);
                        $mass = app(AssertDecommissionMassApproval::class);
                        if ($mass->requiresSecondApprover($count, $siteId)) {
                            try {
                                $approverUserId = $this->resolveMassApprover(
                                    (string) ($data['approver_email'] ?? ''),
                                    (string) ($data['approver_password'] ?? ''),
                                );
                            } catch (InvalidArgumentException $exception) {
                                $this->flash('error', $exception->getMessage());
                                Notification::make()
                                    ->title('Decommission failed')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        $epcIds = array_map(fn (array $row): int => (int) $row['epc_id'], $this->confirmed);
                        $eligibilityError = $this->assertConfirmedStillEligible(
                            $epcIds,
                            $siteId,
                            $shippable,
                            $receivingGate,
                            app(EpcOnOpenShippingSession::class),
                            app(EpcOnOpenTransferringSession::class),
                            app(EpcOnAnotherOpenReceivingSession::class),
                        );
                        if ($eligibilityError !== null) {
                            $this->flash('error', $eligibilityError);
                            Notification::make()
                                ->title('Decommission failed')
                                ->body($eligibilityError)
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $result = $emit->handle($epcIds, $siteId, [
                                'sync' => true,
                                'dispatch' => true,
                                'reason' => $reason,
                                'approver_user_id' => $approverUserId,
                            ]);
                        } catch (InvalidArgumentException|LockTimeoutException|Throwable $exception) {
                            $this->flash('error', $exception->getMessage());
                            Notification::make()
                                ->title('Decommission failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $decommissioned = (int) ($result['decommissioned_count'] ?? 0);
                        $driftCount = (int) ($result['drift_count'] ?? 0);
                        $message = "Decommissioned {$decommissioned} EPC".($decommissioned === 1 ? '' : 's')
                            .' ('.$reason->label().' → '.$reason->dispositionUri().').';
                        if (filled($result['drift_notes'] ?? null)) {
                            $message .= ' '.$result['drift_notes'];
                        }

                        $this->confirmed = [];
                        $this->decommissionReason = null;

                        if ($decommissioned > 0 && $driftCount > 0) {
                            $this->flash('warn', $message);
                            Notification::make()
                                ->title('Decommissioned with aggregation drift')
                                ->body($message)
                                ->warning()
                                ->send();
                        } elseif ($decommissioned > 0) {
                            $this->flash('ok', $message);
                            Notification::make()
                                ->title('Decommission complete')
                                ->body($message)
                                ->success()
                                ->send();
                        } else {
                            $this->flash('warn', $message);
                            Notification::make()
                                ->title('Nothing decommissioned')
                                ->body($message)
                                ->success()
                                ->send();
                        }
                        $this->dispatch('focus-scan');
                    }),
                'decommission_workstation_complete',
                requireReason: true,
                subject: fn (): ?Site => $this->selectedSite(),
            ),
        ];
    }

    private function resolveMassApprover(string $email, string $password): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || $password === '') {
            throw new InvalidArgumentException('Second approver email and password are required for mass decommission.');
        }

        $approver = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (! $approver instanceof User) {
            throw new InvalidArgumentException('Second approver was not found.');
        }

        if ((int) $approver->getKey() === (int) auth()->id()) {
            throw new InvalidArgumentException('Mass decommission cannot be self-approved.');
        }

        if (! Hash::check($password, (string) $approver->password)) {
            throw new InvalidArgumentException('Second approver password is incorrect.');
        }

        if (! $approver->can(Permissions::DecommissionMassApprove)) {
            throw new InvalidArgumentException('Second approver is not authorized for mass decommission approval.');
        }

        return (int) $approver->getKey();
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function assertConfirmedStillEligible(
        array $epcIds,
        int $siteId,
        ShippableEpcsAtSite $shippable,
        ReceivingGate $receivingGate,
        EpcOnOpenShippingSession $epcOnOpenShippingSession,
        EpcOnOpenTransferringSession $epcOnOpenTransferringSession,
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
                return 'An EPC is quarantined and cannot be decommissioned.';
            }

            if ($epcOnOpenShippingSession->exists($epc)) {
                return 'An EPC is already confirmed on an open ship order.';
            }

            if ($epcOnOpenTransferringSession->exists($epc)) {
                return 'An EPC is already confirmed on an open or in-transit transfer.';
            }

            if ($epcOnAnotherOpenReceivingSession->existsOnAnyExclusiveSession($epc)) {
                return 'An EPC is already confirmed on an open receive session.';
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

    public static function getDocumentation(): array|string
    {
        return 'workflows.decommission';
    }
}

<?php

namespace App\Filament\Resources\Pages;

use App\Filament\Support\RegulatoryCompliance;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord as FilamentCreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Create form submit actions: primary background + icon.
 *
 * Scan-session open pages (transfer / receive / outbound ship) should override
 * {@see shouldGateCreateWithRegulatoryCompliance()} to false — password confirmation
 * belongs on ship/complete/send after scans, not on opening the workstation.
 */
abstract class CreateRecord extends FilamentCreateRecord
{
    /**
     * When false, Create / Create & create another submit without the regulatory
     * password modal (still audited elsewhere for floor workflows).
     */
    protected function shouldGateCreateWithRegulatoryCompliance(): bool
    {
        return true;
    }

    protected function getCreateFormAction(): Action
    {
        $action = parent::getCreateFormAction()
            ->color('primary')
            ->icon(Heroicon::OutlinedPlus);

        return $this->gateCreateFormAction($action, 'create_record', fn () => $this->create());
    }

    protected function getCreateAnotherFormAction(): Action
    {
        $action = parent::getCreateAnotherFormAction()
            ->color('primary')
            ->icon(Heroicon::OutlinedPlusCircle);

        return $this->gateCreateFormAction($action, 'create_another_record', fn () => $this->createAnother());
    }

    /**
     * Form footer submit buttons bypass Action modals; convert to confirmed modal + password when gated.
     *
     * @param  callable(): void  $submit
     */
    private function gateCreateFormAction(Action $action, string $actionName, callable $submit): Action
    {
        if (
            ! $this->shouldGateCreateWithRegulatoryCompliance()
            || ! RegulatoryCompliance::enabled()
            || ! RegulatoryCompliance::isAppPanel()
        ) {
            return $action;
        }

        return $action
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading('Confirm create')
            ->schema(RegulatoryCompliance::fields(requireReason: false))
            ->mountUsing(function (?Schema $schema = null): void {
                $this->form->validate();
                $schema?->fill();
            })
            ->action(function (array $data, Action $action) use ($actionName, $submit): void {
                RegulatoryCompliance::assert($data, $actionName, $action);
                RegulatoryCompliance::audit($actionName);
                RegulatoryCompliance::markVerified($actionName);
                $submit();
            });
    }

    protected function beforeCreate(): void
    {
        if (
            ! $this->shouldGateCreateWithRegulatoryCompliance()
            || ! RegulatoryCompliance::enabled()
            || ! RegulatoryCompliance::isAppPanel()
        ) {
            return;
        }

        RegulatoryCompliance::requireVerified('create_record', 'create_another_record');
    }
}

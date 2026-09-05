<?php

namespace App\Filament\Admin\Resources\Tenants\Pages;

use App\Actions\MasterData\RederiveOrganizationSglns;
use App\Actions\Tenants\CascadeTenantPairKillSwitches;
use App\Actions\Tenants\CascadeTenantPairStatus;
use App\Actions\Tenants\DeleteTenantPair;
use App\Filament\Admin\Resources\Tenants\Actions\ExportTenantComplianceArchiveAction;
use App\Filament\Admin\Resources\Tenants\Actions\ImpersonateTenantUserAction;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Models\Tenant;
use App\Support\TenantSettings;
use App\Support\Tenancy\TenantKillSwitches;
use Filament\Actions\DeleteAction;
use App\Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /** @var array<string, string> */
    private const KILL_SWITCH_FORM_KEYS = [
        'kill_switch_outbound_epcis' => TenantKillSwitches::OUTBOUND_EPCIS,
        'kill_switch_inbound_epcis' => TenantKillSwitches::INBOUND_EPCIS,
        'kill_switch_sanctum_api' => TenantKillSwitches::SANCTUM_API,
        'kill_switch_wms_webhooks' => TenantKillSwitches::WMS_WEBHOOKS,
    ];

    /** @var list<string> */
    private const ADDRESS_KEYS = [
        'street_address',
        'street_address_2',
        'city',
        'state',
        'zipcode',
        'country_code',
    ];

    /** @var array<string, mixed> */
    protected array $organizationAddress = [];

    /** @var array<string, bool> */
    protected array $killSwitches = [];

    /** @var array<string, mixed> */
    protected array $ssoConfig = [];

    private ?string $previousStatus = null;

    protected function getHeaderActions(): array
    {
        return [
            ExportTenantComplianceArchiveAction::make(),
            ImpersonateTenantUserAction::make(),
            DeleteAction::make()
                ->modalDescription(fn (Tenant $record): string => app(DeleteTenantPair::class)->deleteModalDescription($record))
                ->schema(fn (Tenant $record): array => app(DeleteTenantPair::class)->deleteModalSchema($record))
                ->before(function (Tenant $record, array $data): void {
                    try {
                        app(DeleteTenantPair::class)->assertDeleteAllowed($record, $data);
                    } catch (\DomainException $exception) {
                        Notification::make()
                            ->title('Delete blocked')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        throw new Halt;
                    }

                    app(DeleteTenantPair::class)->deleteSibling($record);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $settings = TenantSettings::forTenant($this->record);
        $sso = $settings->ssoConfig();

        return array_merge(
            $data,
            $settings->organizationAddress(),
            [
                'kill_switch_outbound_epcis' => $settings->outboundEpcisKilled(),
                'kill_switch_inbound_epcis' => $settings->inboundEpcisKilled(),
                'kill_switch_sanctum_api' => $settings->sanctumApiKilled(),
                'kill_switch_wms_webhooks' => $settings->wmsWebhooksKilled(),
                'sso_enabled' => $sso['enabled'],
                'sso_only' => $sso['sso_only'],
                'sso_provider' => $sso['provider'],
                'sso_issuer' => $sso['issuer'],
                'sso_client_id' => $sso['client_id'],
                'sso_client_secret' => null,
                'sso_entra_tenant_id' => $sso['entra_tenant_id'],
                'sso_jit_default_role' => $sso['jit_default_role'],
                'sso_allowed_email_domains' => implode(', ', $sso['allowed_email_domains']),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record instanceof Tenant) {
            $this->previousStatus = is_string($this->record->status) ? $this->record->status : null;
        }

        $this->organizationAddress = [];
        $this->killSwitches = [];
        $this->ssoConfig = [];

        foreach (self::ADDRESS_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $this->organizationAddress[$key] = $data[$key];
            unset($data[$key]);
        }

        foreach (self::KILL_SWITCH_FORM_KEYS as $formKey => $switchKey) {
            if (! array_key_exists($formKey, $data)) {
                continue;
            }

            $this->killSwitches[$switchKey] = (bool) $data[$formKey];
            unset($data[$formKey]);
        }

        $ssoKeys = [
            'sso_enabled' => 'enabled',
            'sso_only' => 'sso_only',
            'sso_provider' => 'provider',
            'sso_issuer' => 'issuer',
            'sso_client_id' => 'client_id',
            'sso_client_secret' => 'client_secret',
            'sso_entra_tenant_id' => 'entra_tenant_id',
            'sso_jit_default_role' => 'jit_default_role',
            'sso_allowed_email_domains' => 'allowed_email_domains',
        ];

        foreach ($ssoKeys as $formKey => $configKey) {
            if (! array_key_exists($formKey, $data)) {
                continue;
            }

            $this->ssoConfig[$configKey] = $data[$formKey];
            unset($data[$formKey]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $prefixChanged = $this->record->wasChanged('company_prefix');
        $statusChanged = $this->record->wasChanged('status');

        TenantSettings::forTenant($this->record)->saveOrganization($this->organizationAddress);

        if ($this->ssoConfig !== []) {
            TenantSettings::forTenant($this->record)->saveSsoConfig($this->ssoConfig);
            $this->record->save();
        }

        if ($this->killSwitches !== []) {
            TenantSettings::forTenant($this->record)->setKillSwitches($this->killSwitches);
            $this->record->save();
            app(CascadeTenantPairKillSwitches::class)->handle($this->record, $this->killSwitches);
        }

        if ($statusChanged) {
            app(CascadeTenantPairStatus::class)->handle($this->record, $this->previousStatus);
        }

        if ($prefixChanged) {
            $this->rederiveOrganizationSglns();
        }
    }

    /**
     * A new company prefix re-splits our own GLNs, so the SGLNs stored for the tenant's
     * facilities now name locations under a prefix it no longer claims. Organization
     * Settings re-derives them on save; this panel writes tenant identity from the
     * central database, where those tables are out of reach — so enter the tenant and
     * run the same repair `tracepharma:rederive-organization-sglns` runs.
     */
    private function rederiveOrganizationSglns(): void
    {
        $tenant = $this->record;

        if (! $tenant instanceof Tenant) {
            return;
        }

        $prefix = TenantSettings::forTenant($tenant)->companyPrefix();

        try {
            $tenant->run(fn () => app(RederiveOrganizationSglns::class)->handle($prefix));
        } catch (\Throwable $e) {
            // run() leaves tenancy initialized when the callback throws.
            tenancy()->end();

            report($e);

            Notification::make()
                ->danger()
                ->title('Company prefix saved, but facility SGLNs were not re-derived')
                ->body(
                    'This tenant\'s organization SGLNs still encode the previous prefix. Repair them with: '
                    ."php artisan tracepharma:rederive-organization-sglns --tenants={$tenant->getKey()}",
                )
                ->persistent()
                ->send();

            $this->halt();
        }
    }
}

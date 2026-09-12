<?php

namespace App\Filament\Admin\Resources\CustomerOnboardings\Pages;

use App\Actions\CustomerOnboarding\ApproveAndProvisionCustomerOnboarding;
use App\Enums\CustomerOnboardingStatus;
use App\Filament\Admin\Resources\CustomerOnboardings\CustomerOnboardingResource;
use App\Models\CustomerOnboarding;
use App\Rules\ValidGln;
use App\Support\Auth\Permissions;
use App\Support\TenantHostname;
use App\Support\TenantPairAvailability;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use App\Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Auth;

class ViewCustomerOnboarding extends ViewRecord
{
    protected static string $resource = CustomerOnboardingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveAndProvision')
                ->label('Approve & provision tenant')
                ->icon('heroicon-o-building-storefront')
                ->color('success')
                ->visible(fn (CustomerOnboarding $record): bool => $record->isProvisionable()
                    && auth('admin')->user()?->can(Permissions::TenantsManage))
                ->modalHeading('Provision tenant from application')
                ->modalDescription('Creates a tenant host and an initial owner account.')
                ->fillForm(fn (CustomerOnboarding $record): array => [
                    'tenant_slug' => filled($record->tenant_slug)
                        ? $record->tenant_slug
                        : ApproveAndProvisionCustomerOnboarding::suggestSlug($record->company_display_name),
                    'gln' => $record->gln,
                    'owner_name' => $record->contact_name,
                    'owner_email' => $record->contact_email,
                    'admin_notes' => $record->admin_notes,
                ])
                ->schema([
                    TextInput::make('tenant_slug')
                        ->label('Tenant slug')
                        ->required()
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->maxLength(63)
                        ->rules([
                            fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                $record = $this->getRecord();
                                $slug = strtolower((string) $value);
                                $error = $record instanceof CustomerOnboarding
                                    ? TenantPairAvailability::validationMessageFor($record, $slug)
                                    : TenantPairAvailability::validationMessage($slug);

                                if ($error !== null) {
                                    $fail($error);
                                }
                            },
                        ])
                        ->helperText('Creates hosts '.$this->slugHint()),
                    TextInput::make('gln')
                        ->label('GLN')
                        ->length(13)
                        ->nullable()
                        ->rules([new ValidGln]),
                    TextInput::make('owner_name')
                        ->label('Owner name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('owner_email')
                        ->label('Owner email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('owner_password')
                        ->label('Owner password')
                        ->password()
                        ->required()
                        ->minLength(8)
                        ->revealable(),
                    Textarea::make('admin_notes')
                        ->label('Admin notes')
                        ->rows(3),
                ])
                ->action(function (CustomerOnboarding $record, array $data, ApproveAndProvisionCustomerOnboarding $action): void {
                    $admin = Auth::guard('admin')->user();

                    if ($admin === null) {
                        throw new Halt;
                    }

                    try {
                        $tenant = $action->execute($record, $data, (int) $admin->id);
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Provisioning failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        throw new Halt;
                    }

                    $hostname = $tenant->domains->first()?->domain
                        ?? TenantHostname::forSlug((string) $data['tenant_slug'], 'prod');

                    Notification::make()
                        ->title('Tenant provisioned')
                        ->body('Created tenant '.$tenant->name.' at '.TenantHostname::pairHint((string) $data['tenant_slug']).' (prod: '.$hostname.').')
                        ->success()
                        ->send();
                }),
            Action::make('reject')
                ->label('Reject application')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (CustomerOnboarding $record): bool => $record->status?->canReject() ?? false)
                ->requiresConfirmation()
                ->modalHeading('Reject customer onboarding application')
                ->schema([
                    Textarea::make('rejection_reason')
                        ->label('Reason')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (CustomerOnboarding $record, array $data): void {
                    $record->reject((string) $data['rejection_reason']);

                    Notification::make()
                        ->title('Application rejected')
                        ->success()
                        ->send();
                }),
            Action::make('openTenant')
                ->label('Open tenant')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(function (CustomerOnboarding $record): ?string {
                    $domain = $record->tenant?->domains()->orderBy('id')->value('domain');

                    return $domain !== null ? 'https://'.$domain : null;
                })
                ->openUrlInNewTab()
                ->visible(fn (CustomerOnboarding $record): bool => $record->hasOpenableTenant()),
        ];
    }

    private function slugHint(): string
    {
        return TenantHostname::pairHint();
    }
}

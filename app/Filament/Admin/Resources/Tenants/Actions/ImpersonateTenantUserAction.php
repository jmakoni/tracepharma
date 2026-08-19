<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Actions\Admin\StartTenantUserImpersonation;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Tenancy\TenantAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

final class ImpersonateTenantUserAction
{
    public static function make(): Action
    {
        return Action::make('impersonateTenantUser')
            ->label('Impersonate user')
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('warning')
            ->visible(fn (): bool => auth('admin')->user()?->can(Permissions::TenantsManage) ?? false)
            ->disabled(fn (Tenant $record): bool => ! TenantAccess::isActive($record))
            ->modalHeading('Impersonate tenant user')
            ->modalDescription('Sign in as a tenant user for support. This action is audited and requires a reason.')
            ->schema(fn (Action $action): array => [
                Select::make('user_id')
                    ->label('User')
                    ->options(function () use ($action): array {
                        $record = $action->getRecord();

                        return $record instanceof Tenant
                            ? self::tenantUserOptions($record)
                            : [];
                    })
                    ->searchable()
                    ->required(),
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->helperText('Required for audit trail.'),
            ])
            ->action(function (Tenant $record, array $data, StartTenantUserImpersonation $starter) {
                $admin = auth('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                try {
                    $url = $starter->execute(
                        $admin,
                        $record,
                        (string) $data['user_id'],
                        (string) $data['reason'],
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Impersonation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                Notification::make()
                    ->title('Opening tenant session')
                    ->success()
                    ->send();

                return redirect()->away($url);
            });
    }

    /**
     * @return array<string, string>
     */
    private static function tenantUserOptions(Tenant $tenant): array
    {
        return $tenant->run(function (): array {
            return User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->mapWithKeys(static fn (User $user): array => [
                    (string) $user->getKey() => trim($user->name.' ('.$user->email.')'),
                ])
                ->all();
        });
    }
}

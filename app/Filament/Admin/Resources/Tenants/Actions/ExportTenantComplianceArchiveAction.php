<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Tenants\Actions;

use App\Actions\Tenants\QueueTenantComplianceExport;
use App\Models\Admin;
use App\Models\Tenant;
use App\Support\Auth\Permissions;
use App\Support\TenantSettings;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

final class ExportTenantComplianceArchiveAction
{
    public static function make(): Action
    {
        return Action::make('exportComplianceArchive')
            ->label('Export compliance archive')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('gray')
            ->visible(fn (): bool => auth('admin')->user()?->can(Permissions::TenantsManage) ?? false)
            ->modalHeading('Export compliance archive')
            ->modalDescription(fn (Tenant $record): string => self::modalDescription($record))
            ->requiresConfirmation()
            ->action(function (Tenant $record, QueueTenantComplianceExport $queueExport): void {
                $admin = auth('admin')->user();

                if (! $admin instanceof Admin) {
                    throw new Halt;
                }

                $queueExport->execute($admin, $record);

                Notification::make()
                    ->title('Compliance export queued')
                    ->body('The archive will be written to central storage when the job completes.')
                    ->success()
                    ->send();
            });
    }

    private static function modalDescription(Tenant $record): string
    {
        $exportedAt = TenantSettings::forTenant($record)->complianceLastExportAt();

        $base = 'Packages bounded tenant data (activity log, master data, EPCIS index) for retention. Audit history is not purged by this export.';

        if ($exportedAt === null) {
            return $base.' No prior export on record.';
        }

        return $base.' Last export: '.$exportedAt->timezone(config('app.timezone'))->format('Y-m-d H:i T').'.';
    }
}

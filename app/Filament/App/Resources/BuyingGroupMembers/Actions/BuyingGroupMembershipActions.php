<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BuyingGroupMembers\Actions;

use App\Actions\BuyingGroup\InviteBuyingGroupMembership;
use App\Actions\BuyingGroup\RevokeBuyingGroupMembership;
use App\Enums\BuyingGroupMembershipStatus;
use App\Enums\TenantProfile;
use App\Filament\Notifications\Notification;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMembership;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

final class BuyingGroupMembershipActions
{
    public static function invite(): Action
    {
        return Action::make('inviteHardMembership')
            ->label('Invite TracePharma tenant')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->visible(function (?BuyingGroupMember $record): bool {
                if ($record === null) {
                    return false;
                }

                return ! self::openMembershipFor($record)->exists();
            })
            ->form([
                Select::make('member_tenant_id')
                    ->label('Pharmacy tenant')
                    ->options(fn (): array => Tenant::query()
                        ->where('profile', TenantProfile::Pharmacy)
                        ->where('status', 'active')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Sends a pending invite to Owners of the selected pharmacy tenant.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Invite pharmacy to hard-link')
            ->modalDescription('Creates a central pending membership. Soft roster identity stays until they accept.')
            ->action(function (BuyingGroupMember $record, array $data): void {
                $user = auth()->user();
                $buyingGroup = tenant();
                $memberTenant = Tenant::query()->find($data['member_tenant_id'] ?? null);

                if (! $user instanceof User || ! $buyingGroup instanceof Tenant || ! $memberTenant instanceof Tenant) {
                    Notification::make()->title('Unable to send invite')->danger()->send();

                    return;
                }

                try {
                    app(InviteBuyingGroupMembership::class)->invite(
                        $buyingGroup,
                        $memberTenant,
                        $user,
                        (int) $record->getKey(),
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Invite sent')
                    ->body('Pharmacy Owners can accept from Organization settings.')
                    ->success()
                    ->send();
            });
    }

    public static function revoke(): Action
    {
        return Action::make('revokeHardMembership')
            ->label('Revoke hard link')
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('danger')
            ->visible(fn (?BuyingGroupMember $record): bool => $record !== null
                && self::openMembershipFor($record)->exists())
            ->form([
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(3)
                    ->required(),
            ])
            ->requiresConfirmation()
            ->action(function (BuyingGroupMember $record, array $data): void {
                $user = auth()->user();
                $membership = self::openMembershipFor($record)->latest('id')->first();

                if (! $user instanceof User || $membership === null) {
                    Notification::make()->title('No active membership to revoke')->danger()->send();

                    return;
                }

                try {
                    app(RevokeBuyingGroupMembership::class)->revoke(
                        $membership,
                        $user,
                        is_string($data['reason'] ?? null) ? $data['reason'] : null,
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Hard link revoked')->success()->send();
            });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<BuyingGroupMembership>
     */
    private static function openMembershipFor(BuyingGroupMember $record)
    {
        return BuyingGroupMembership::query()
            ->where('buying_group_tenant_id', tenant()?->getKey())
            ->where(function ($query) use ($record): void {
                $query->where('bg_member_local_id', $record->getKey());

                if (filled($record->member_tenant_id)) {
                    $query->orWhere('member_tenant_id', $record->member_tenant_id);
                }
            })
            ->whereIn('status', [
                BuyingGroupMembershipStatus::Pending->value,
                BuyingGroupMembershipStatus::Active->value,
            ]);
    }
}

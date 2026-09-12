<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\BuyingGroupMembers\Actions;

use App\Actions\BuyingGroup\InviteBuyingGroupMembership;
use App\Actions\BuyingGroup\RevokeBuyingGroupMembership;
use App\Enums\BuyingGroupMembershipStatus;
use App\Filament\Notifications\Notification;
use App\Models\BuyingGroupMember;
use App\Models\BuyingGroupMembership;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                TextInput::make('member_tenant_lookup')
                    ->label('Pharmacy tenant ID or domain')
                    ->placeholder('UUID or primary hostname')
                    ->required()
                    ->helperText('Enter the pharmacy’s tenant UUID or primary domain (out-of-band). This is not a platform pharmacy directory.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Invite pharmacy to hard-link')
            ->modalDescription('Creates a central pending membership. Soft roster identity stays until they accept.')
            ->action(function (BuyingGroupMember $record, array $data): void {
                $user = auth()->user();
                $buyingGroup = tenant();
                $invite = app(InviteBuyingGroupMembership::class);

                if (! $user instanceof User || ! $buyingGroup instanceof Tenant) {
                    Notification::make()->title('Unable to send invite')->danger()->send();

                    return;
                }

                try {
                    $lookup = is_string($data['member_tenant_lookup'] ?? null)
                        ? $data['member_tenant_lookup']
                        : '';
                    $memberTenant = $invite->resolveMemberTenant($lookup);
                    $invite->invite(
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

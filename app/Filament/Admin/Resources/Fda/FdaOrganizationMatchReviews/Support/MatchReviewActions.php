<?php

namespace App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\Support;

use App\Actions\Fda\ResolveFdaOrganizationMatchReview;
use App\Filament\Admin\Resources\Fda\FdaOrganizationMatchReviews\FdaOrganizationMatchReviewResource;
use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Support\Auth\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use App\Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class MatchReviewActions
{
    public static function canCurate(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::CatalogManage);
    }

    public static function link(): Action
    {
        return Action::make('linkOrganization')
            ->label('Link')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (FdaOrganizationMatchReview $record): bool => self::canMutate($record))
            ->fillForm(fn (FdaOrganizationMatchReview $record): array => [
                'fda_organization_id' => $record->proposed_fda_organization_id,
            ])
            ->schema([
                Select::make('fda_organization_id')
                    ->label('Organization')
                    ->required()
                    ->searchable()
                    ->preload(false)
                    ->getSearchResultsUsing(fn (string $search): array => FdaOrganization::query()
                        ->where(function ($query) use ($search): void {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('canonical_name', 'like', "%{$search}%")
                                ->orWhere('original_name', 'like', "%{$search}%");
                        })
                        ->orderBy('name')
                        ->limit(25)
                        ->get()
                        ->mapWithKeys(fn (FdaOrganization $org): array => [
                            $org->id => $org->name ?: $org->canonical_name,
                        ])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => FdaOrganization::query()->find($value)?->name),
            ])
            ->action(function (FdaOrganizationMatchReview $record, array $data): void {
                $org = FdaOrganization::query()->findOrFail($data['fda_organization_id']);
                app(ResolveFdaOrganizationMatchReview::class)->link($record, $org, self::actor());
                Notification::make()->title('Linked to '.$org->name)->success()->send();
            });
    }

    public static function createOrganization(): Action
    {
        return Action::make('createOrganization')
            ->label('Create new organization')
            ->icon(Heroicon::OutlinedPlus)
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription('Creates an organization from this review name. The next import can attach establishments and facilities.')
            ->visible(fn (FdaOrganizationMatchReview $record): bool => self::canMutate($record))
            ->action(function (FdaOrganizationMatchReview $record): void {
                $org = app(ResolveFdaOrganizationMatchReview::class)->createOrganization($record, self::actor());
                Notification::make()->title('Organization '.$org->name)->success()->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('rejectReview')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (FdaOrganizationMatchReview $record): bool => self::canMutate($record))
            ->action(function (FdaOrganizationMatchReview $record): void {
                app(ResolveFdaOrganizationMatchReview::class)->reject($record, self::actor());
                Notification::make()->title('Review rejected')->success()->send();
            });
    }

    public static function skip(): Action
    {
        return Action::make('skipReview')
            ->label('Skip')
            ->icon(Heroicon::OutlinedForward)
            ->color('gray')
            ->visible(fn (FdaOrganizationMatchReview $record): bool => self::canMutate($record))
            ->action(function (FdaOrganizationMatchReview $record) {
                $next = FdaOrganizationMatchReview::query()
                    ->pending()
                    ->where('id', '!=', $record->id)
                    ->orderBy('id')
                    ->first();

                return $next instanceof FdaOrganizationMatchReview
                    ? redirect(FdaOrganizationMatchReviewResource::getUrl('view', ['record' => $next]))
                    : redirect(FdaOrganizationMatchReviewResource::getUrl('index'));
            });
    }

    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::link(),
            self::createOrganization(),
            self::reject(),
            self::skip(),
        ];
    }

    private static function canMutate(FdaOrganizationMatchReview $record): bool
    {
        return self::canCurate() && $record->status === FdaOrganizationMatchReview::STATUS_PENDING;
    }

    private static function actor(): Admin
    {
        $admin = auth('admin')->user();

        if (! $admin instanceof Admin) {
            throw new \RuntimeException('Match review actions require an admin.');
        }

        return $admin;
    }
}

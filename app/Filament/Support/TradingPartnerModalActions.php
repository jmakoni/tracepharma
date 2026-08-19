<?php

namespace App\Filament\Support;

use App\Actions\MasterData\CreateHqSiteForTradingPartner;
use App\Actions\MasterData\CreatePickedFdaSiteForTradingPartner;
use App\Models\TradingPartner;
use App\Support\PartnerSlug;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared Create / Edit modal wiring for App Trading Partners.
 */
final class TradingPartnerModalActions
{
    /**
     * @param  class-string<resource>  $resource
     */
    public static function create(CreateAction $action, string $resource, bool $assignSlug = false): CreateAction
    {
        $action = $action
            ->modal()
            ->modalWidth(Width::FiveExtraLarge)
            ->createAnother(false)
            ->using(function (array $data): TradingPartner {
                $pick = is_string($data['fda_pick'] ?? null) ? $data['fda_pick'] : null;
                unset($data['fda_pick']);

                $partner = TradingPartner::query()->create($data);
                self::ensureHqSite($partner, $pick);
                app(CreatePickedFdaSiteForTradingPartner::class)->handle($partner, $pick);

                return $partner;
            })
            ->successRedirectUrl(fn (Model $record): string => $resource::getUrl('view', ['record' => $record]));

        if ($assignSlug) {
            $action->mutateDataUsing(function (array $data): array {
                if (blank($data['slug'] ?? null) && filled($data['name'] ?? null)) {
                    $data['slug'] = PartnerSlug::from((string) $data['name']);
                }

                return $data;
            });
        }

        return RegulatoryCompliance::apply($action, 'trading_partner_create', requireReason: false);
    }

    public static function edit(EditAction $action, bool $lockSlug = false): EditAction
    {
        $action = $action
            ->modal()
            ->modalHeading('Update partner')
            ->modalWidth(Width::FiveExtraLarge);

        if ($lockSlug) {
            $action->mutateDataUsing(function (array $data, Model $record): array {
                $data['slug'] = $record->getAttribute('slug');

                return $data;
            });
        }

        $action->after(function (Model $record): void {
            if (! $record instanceof TradingPartner || blank($record->fda_organization_id)) {
                return;
            }

            self::ensureHqSite($record);
        });

        return RegulatoryCompliance::apply($action, 'trading_partner_edit', requireReason: false);
    }

    /**
     * The HQ site is skipped when the partner GLN already belongs to another site — most
     * often one of our own facilities, which means the partner is really us. Say so
     * instead of leaving the operator with a partner that silently has no location.
     */
    private static function ensureHqSite(Model $record, ?string $pick = null): void
    {
        if (! $record instanceof TradingPartner) {
            return;
        }

        if (app(CreateHqSiteForTradingPartner::class)->handle($record, $pick) !== null) {
            return;
        }

        Notification::make()
            ->title('Headquarters site not created')
            ->body('GLN '.$record->gln.' already belongs to another site, usually one of your own facilities. Add the site by hand if this partner really needs one.')
            ->warning()
            ->persistent()
            ->send();
    }
}

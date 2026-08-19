<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

/**
 * Groups table row actions under a horizontal ellipsis icon button.
 * Row actions (trigger + items) use secondary; page/submit actions are unchanged.
 */
final class RecordActionGroup
{
    /**
     * @param  array<int, Action|ActionGroup>  $actions
     */
    public static function make(array $actions): ActionGroup
    {
        $actions = array_map(
            static function (Action|ActionGroup $action): Action|ActionGroup {
                return $action->color('secondary');
            },
            $actions,
        );

        return ActionGroup::make($actions)
            ->label('Actions')
            ->icon(Heroicon::EllipsisHorizontal)
            ->iconButton()
            ->color('secondary')
            ->tooltip('Actions');
    }
}

<?php

namespace Tracepharma\FilamentUiExtras\Concerns\Pages;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Tracepharma\FilamentUiExtras\Enums\DualSubNavigationPosition;

trait HasDualSubNavigation
{
    /**
     * @return array<NavigationItem | NavigationGroup>
     */
    protected function getDualSubNavigationItems(): array
    {
        return [];
    }

    protected function getDualSubNavigationPosition(): DualSubNavigationPosition
    {
        return DualSubNavigationPosition::Start;
    }

    /**
     * @return array<NavigationGroup>
     */
    public function getCachedDualSubNavigation(): array
    {
        $items = $this->getDualSubNavigationItems();

        if ($items === []) {
            return [];
        }

        $groups = [];
        $ungrouped = [];

        foreach ($items as $item) {
            if ($item instanceof NavigationGroup) {
                $groups[] = $item;

                continue;
            }

            $ungrouped[] = $item;
        }

        if ($ungrouped !== []) {
            $groups[] = NavigationGroup::make()->items($ungrouped);
        }

        return $groups;
    }

    public function getDualSubNavigationPositionEnum(): DualSubNavigationPosition
    {
        return $this->getDualSubNavigationPosition();
    }

    /**
     * Map dual-nav position to Filament's SubNavigationPosition for shared Blade components.
     */
    public function getDualSubNavigationFilamentPosition(): SubNavigationPosition
    {
        return match ($this->getDualSubNavigationPosition()) {
            DualSubNavigationPosition::Start => SubNavigationPosition::Start,
            DualSubNavigationPosition::Top => SubNavigationPosition::Top,
            DualSubNavigationPosition::End => SubNavigationPosition::End,
        };
    }
}

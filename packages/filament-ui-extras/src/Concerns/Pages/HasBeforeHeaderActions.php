<?php

namespace Tracepharma\FilamentUiExtras\Concerns\Pages;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Tracepharma\FilamentUiExtras\FilamentUiExtrasPlugin;

trait HasBeforeHeaderActions
{
    /**
     * @return array<Action>
     */
    protected function getBeforeHeaderActions(): array
    {
        return [];
    }

    protected function hasDefaultBeforeHeaderBackAction(): bool
    {
        $plugin = FilamentUiExtrasPlugin::tryGet();

        return $plugin?->hasDefaultBackAction() ?? true;
    }

    protected function getDefaultBeforeHeaderBackAction(): ?Action
    {
        if (! $this->hasDefaultBeforeHeaderBackAction()) {
            return null;
        }

        return Action::make('uieBack')
            ->label(__('filament-ui-extras::ui.back'))
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->alpineClickHandler('window.history.back()');
    }

    /**
     * @return array<Action>
     */
    public function getCachedBeforeHeaderActions(): array
    {
        $actions = $this->getBeforeHeaderActions();

        $default = $this->getDefaultBeforeHeaderBackAction();

        if ($default) {
            array_unshift($actions, $default);
        }

        foreach ($actions as $action) {
            if ($action->getLivewire() === null) {
                $action->livewire($this);
            }
        }

        return array_values(array_filter(
            $actions,
            static fn (Action $action): bool => $action->isVisible(),
        ));
    }

    public function renderBeforeHeaderActions(): Htmlable|string
    {
        $actions = $this->getCachedBeforeHeaderActions();

        if ($actions === []) {
            return '';
        }

        return new HtmlString(
            view('filament-ui-extras::components.hooks.before-header-actions', [
                'actions' => $actions,
            ])->render()
        );
    }
}

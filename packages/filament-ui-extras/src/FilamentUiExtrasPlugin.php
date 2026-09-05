<?php

namespace Tracepharma\FilamentUiExtras;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;

class FilamentUiExtrasPlugin implements Plugin
{
    protected bool|Closure $hasLoadingBar = true;

    protected bool|Closure $hasDefaultBackAction = true;

    protected bool|Closure $hasStickyTableActions = false;

    protected bool|Closure $hasFaviconSpinner = false;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function tryGet(): ?static
    {
        try {
            /** @var static|null $plugin */
            $plugin = filament()->getPlugin('filament-ui-extras');

            return $plugin;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function get(): static
    {
        $plugin = static::tryGet();

        if (! $plugin) {
            throw new \RuntimeException('FilamentUiExtrasPlugin is not registered on the current panel.');
        }

        return $plugin;
    }

    public function getId(): string
    {
        return 'filament-ui-extras';
    }

    public function loadingBar(bool|Closure $condition = true): static
    {
        $this->hasLoadingBar = $condition;

        return $this;
    }

    public function defaultBackAction(bool|Closure $condition = true): static
    {
        $this->hasDefaultBackAction = $condition;

        return $this;
    }

    public function stickyTableActions(bool|Closure $condition = true): static
    {
        $this->hasStickyTableActions = $condition;

        return $this;
    }

    public function faviconSpinner(bool|Closure $condition = true): static
    {
        $this->hasFaviconSpinner = $condition;

        return $this;
    }

    public function hasLoadingBar(): bool
    {
        return (bool) $this->evaluate($this->hasLoadingBar);
    }

    public function hasDefaultBackAction(): bool
    {
        return (bool) $this->evaluate($this->hasDefaultBackAction);
    }

    public function hasStickyTableActions(): bool
    {
        return (bool) $this->evaluate($this->hasStickyTableActions);
    }

    public function hasFaviconSpinner(): bool
    {
        return (bool) $this->evaluate($this->hasFaviconSpinner);
    }

    public function register(Panel $panel): void
    {
        //
    }

    public function boot(Panel $panel): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function () use ($panel): View|string {
                if (filament()->getCurrentPanel()?->getId() !== $panel->getId()) {
                    return '';
                }

                return view('filament-ui-extras::components.hooks.scripts');
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            function () use ($panel): View|string {
                if (filament()->getCurrentPanel()?->getId() !== $panel->getId()) {
                    return '';
                }

                if (! $this->hasLoadingBar()) {
                    return '';
                }

                return view('filament-ui-extras::components.hooks.loading-bar');
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            function () use ($panel): View|string {
                if (filament()->getCurrentPanel()?->getId() !== $panel->getId()) {
                    return '';
                }

                return view('filament-ui-extras::components.hooks.asset-config', [
                    'faviconSpinner' => $this->hasFaviconSpinner(),
                    'stickyTableActions' => $this->hasStickyTableActions(),
                    'loadingBar' => $this->hasLoadingBar(),
                ]);
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE,
            function (): View|string {
                $livewire = Livewire::current();

                if (! $livewire instanceof LivewireComponent) {
                    return '';
                }

                if (! method_exists($livewire, 'renderBeforeHeaderActions')) {
                    return '';
                }

                return $livewire->renderBeforeHeaderActions();
            },
        );
    }

    protected function evaluate(mixed $value): mixed
    {
        return $value instanceof Closure ? $value() : $value;
    }
}

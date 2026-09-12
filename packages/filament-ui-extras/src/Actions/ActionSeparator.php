<?php

namespace Tracepharma\FilamentUiExtras\Actions;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ActionSeparator extends Action
{
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'uie-action-separator-'.uniqid());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->view('filament-ui-extras::components.actions.separator');
        $this->label(new HtmlString(''));
        $this->disabled();
        $this->extraAttributes([
            'class' => 'fi-uie-action-separator',
            'role' => 'separator',
            'aria-hidden' => 'true',
            'tabindex' => '-1',
        ], merge: true);
    }

    public function getLabel(): string|Htmlable|null
    {
        return new HtmlString(
            '<span class="fi-sr-only">'.e(__('filament-ui-extras::ui.action_separator')).'</span>'
        );
    }
}

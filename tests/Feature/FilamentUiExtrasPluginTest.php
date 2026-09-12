<?php

namespace Tests\Feature;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\SelectFilter;
use Tests\TestCase;
use Tracepharma\FilamentUiExtras\Actions\ActionSeparator;
use Tracepharma\FilamentUiExtras\FilamentUiExtrasPlugin;

class FilamentUiExtrasPluginTest extends TestCase
{
    public function test_plugin_is_registered_on_app_panel(): void
    {
        $panel = filament()->getPanel('app');

        $this->assertTrue($panel->hasPlugin('filament-ui-extras'));

        $plugin = $panel->getPlugin('filament-ui-extras');

        $this->assertInstanceOf(FilamentUiExtrasPlugin::class, $plugin);
        $this->assertTrue($plugin->hasLoadingBar());
        $this->assertFalse($plugin->hasStickyTableActions());
        $this->assertFalse($plugin->hasFaviconSpinner());
    }

    public function test_select_filter_and_form_field_macros_are_registered(): void
    {
        $this->assertTrue(SelectFilter::hasMacro('hiddenLabel'));
        $this->assertTrue(SelectFilter::hasMacro('inlineLabel'));
        $this->assertTrue(Select::hasMacro('inlineLabelPrefix'));
        $this->assertTrue(TextInput::hasMacro('inlineLabelPrefix'));
    }

    public function test_action_separator_can_be_constructed(): void
    {
        $separator = ActionSeparator::make('test-separator');

        $this->assertSame('test-separator', $separator->getName());
        $this->assertTrue($separator->isDisabled());
    }
}

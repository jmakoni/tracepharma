<?php

namespace App\Filament\Admin\Pages;

use App\Models\Admin;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Toggle;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class DashboardPreferences extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'My dashboard';

    protected static ?string $title = 'My dashboard';

    protected static ?int $navigationSort = 2;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.admin.pages.dashboard-preferences';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth('admin')->user() instanceof Admin;
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! $this->allowsCustomize()) {
            return 'Platform defaults are locked. You are seeing the widgets configured for all admins.';
        }

        return 'Choose which widgets appear on your home dashboard. Home shows at most '
            .AdminDashboardWidgetCatalog::HOME_CAP
            .' widgets. More metrics stay on Platform Analytics.';
    }

    protected function fillForm(): void
    {
        $admin = auth('admin')->user();
        $admin = $admin instanceof Admin ? $admin : null;
        $defaults = PlatformSettings::adminDashboardDefaults();
        $prefs = $admin?->hasDashboardWidgetPreferences() ? $admin->dashboardWidgetPreferences() : [];
        $enabled = [];

        foreach ($this->availableAllowedDefinitions() as $definition) {
            $key = $definition['key'];
            $enabled[$key] = array_key_exists($key, $prefs)
                ? (bool) $prefs[$key]
                : (bool) ($defaults[$key] ?? false);
        }

        $this->form->fill([
            'widgets' => $enabled,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->operation('edit')
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        $readOnly = ! $this->allowsCustomize();

        return $schema
            ->components([
                Section::make('Home widgets')
                    ->compact()
                    ->description($readOnly
                        ? 'These widgets are set by platform defaults.'
                        : 'Turn on the widgets you want on home. Primary actions stay visible when enabled, even if you pick more than '
                            .AdminDashboardWidgetCatalog::HOME_CAP
                            .'.')
                    ->schema($this->widgetToggles($readOnly)),
            ]);
    }

    public function save(): void
    {
        if (! $this->allowsCustomize()) {
            Notification::make()
                ->title('Dashboard customization is turned off')
                ->body('Platform defaults control which widgets appear on home.')
                ->warning()
                ->send();

            return;
        }

        $admin = auth('admin')->user();

        if (! $admin instanceof Admin) {
            return;
        }

        $state = $this->form->getState();
        $widgets = is_array($state['widgets'] ?? null) ? $state['widgets'] : [];
        $prefs = [];

        foreach ($this->availableAllowedDefinitions() as $definition) {
            $prefs[$definition['key']] = (bool) ($widgets[$definition['key']] ?? false);
        }

        $admin->setDashboardWidgetPreferences($prefs);
        $admin->save();

        Notification::make()
            ->title('Dashboard preferences saved')
            ->success()
            ->send();

        $this->fillForm();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        if (! $this->allowsCustomize()) {
            return [];
        }

        return [
            Action::make('save')
                ->label('Save')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    /**
     * @return list<Toggle>
     */
    private function widgetToggles(bool $readOnly): array
    {
        $toggles = [];

        foreach ($this->availableAllowedDefinitions() as $definition) {
            $toggles[] = Toggle::make('widgets.'.$definition['key'])
                ->label($definition['label'])
                ->helperText($definition['description'])
                ->disabled($readOnly)
                ->dehydrated(true);
        }

        return $toggles;
    }

    /**
     * @return list<array{
     *     key: string,
     *     kind: 'lean'|'analytics',
     *     label: string,
     *     description: string,
     *     defaultOnHome: bool,
     *     signal: 'flow'|'friction'|'recovery'|'action'
     * }>
     */
    private function availableAllowedDefinitions(): array
    {
        $admin = auth('admin')->user();
        $admin = $admin instanceof Admin ? $admin : null;
        $allowed = PlatformSettings::adminDashboardAllowed();
        $definitions = [];

        foreach (AdminDashboardWidgetCatalog::all() as $definition) {
            $key = $definition['key'];

            if (! ($allowed[$key] ?? true)) {
                continue;
            }

            if (! AdminDashboardWidgetCatalog::isAvailable($key, $admin)) {
                continue;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function allowsCustomize(): bool
    {
        return PlatformSettings::adminDashboardAllowUserCustomize();
    }
}

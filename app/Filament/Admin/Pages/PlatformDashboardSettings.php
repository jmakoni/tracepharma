<?php

namespace App\Filament\Admin\Pages;

use App\Models\Admin;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
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
class PlatformDashboardSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Platform dashboard';

    protected static ?string $title = 'Platform dashboard';

    protected static ?int $navigationSort = 3;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.admin.pages.platform-dashboard-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $admin = auth('admin')->user();

        return $admin instanceof Admin && $admin->can(Permissions::AdminsManage);
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Choose which widgets admins may show on home, and the defaults for people who have not customized yet. Home shows at most '
            .AdminDashboardWidgetCatalog::HOME_CAP
            .' widgets.';
    }

    protected function fillForm(): void
    {
        $this->form->fill([
            'allow_user_customize' => PlatformSettings::adminDashboardAllowUserCustomize(),
            'allowed' => array_keys(array_filter(PlatformSettings::adminDashboardAllowed())),
            'defaults' => array_keys(array_filter(PlatformSettings::adminDashboardDefaults())),
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
        return $schema
            ->components([
                Section::make('Admin home widgets')
                    ->compact()
                    ->description('Allowed widgets can appear on home or Platform Analytics. Defaults apply until an admin saves My dashboard.')
                    ->schema([
                        Toggle::make('allow_user_customize')
                            ->label('Allow admins to customize their dashboard')
                            ->helperText('When off, everyone sees the platform defaults. When on, each admin can pick among allowed widgets.')
                            ->columnSpanFull(),
                        CheckboxList::make('allowed')
                            ->label('Allowed widgets')
                            ->options(fn (): array => $this->widgetOptions())
                            ->descriptions(fn (): array => $this->widgetDescriptions())
                            ->columns(2)
                            ->columnSpanFull(),
                        CheckboxList::make('defaults')
                            ->label('Default widgets on home')
                            ->helperText('Used when an admin has not saved their own dashboard.')
                            ->options(fn (): array => $this->widgetOptions())
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        PlatformSettings::setAdminDashboardAllowUserCustomize((bool) ($state['allow_user_customize'] ?? true));
        PlatformSettings::setAdminDashboardAllowed($this->checkboxListToFlags($state['allowed'] ?? []));
        PlatformSettings::setAdminDashboardDefaults($this->checkboxListToFlags($state['defaults'] ?? []));

        Notification::make()
            ->title('Platform dashboard settings saved')
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
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function widgetOptions(): array
    {
        $options = [];

        foreach (AdminDashboardWidgetCatalog::all() as $definition) {
            $options[$definition['key']] = $definition['label'];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function widgetDescriptions(): array
    {
        $descriptions = [];

        foreach (AdminDashboardWidgetCatalog::all() as $definition) {
            $descriptions[$definition['key']] = $definition['description'];
        }

        return $descriptions;
    }

    /**
     * @param  mixed  $selected
     * @return array<string, bool>
     */
    private function checkboxListToFlags(mixed $selected): array
    {
        $selected = is_array($selected) ? array_map(strval(...), $selected) : [];
        $flags = [];

        foreach (array_keys($this->widgetOptions()) as $key) {
            $flags[$key] = in_array($key, $selected, true);
        }

        return $flags;
    }
}

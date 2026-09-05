<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Support\Admin\TenantImpersonation;
use App\Support\Legal\LegalAcceptance;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class AcceptLegalDocuments extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $navigationLabel = 'Accept legal documents';

    protected static ?string $title = 'Accept Terms and Privacy';

    protected static ?int $navigationSort = 99;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected string $view = 'filament.app.pages.accept-legal-documents';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return LegalAcceptance::isGated();
    }

    public function mount(): void
    {
        $this->form->fill([
            'accept_terms' => false,
            'accept_privacy' => false,
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (TenantImpersonation::isActive()) {
            return 'Impersonation is active. Acceptance must be recorded by the customer, not by support.';
        }

        if (! $this->needsAcceptance()) {
            return 'You have accepted the current Terms of Service and Privacy Policy.';
        }

        return 'Owners and organization administrators must accept the current documents to keep using this workspace.';
    }

    public function needsAcceptance(): bool
    {
        $user = auth()->user();

        return $user instanceof User && LegalAcceptance::isStale($user);
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
                Checkbox::make('accept_terms')
                    ->label('I have read and accept the current Terms of Service')
                    ->accepted()
                    ->disabled(TenantImpersonation::isActive()),
                Checkbox::make('accept_privacy')
                    ->label('I have read and accept the current Privacy Policy')
                    ->accepted()
                    ->disabled(TenantImpersonation::isActive()),
            ]);
    }

    public function accept(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        if (TenantImpersonation::isActive()) {
            Notification::make()
                ->title('Cannot accept while impersonating')
                ->body('Support must not accept Terms or Privacy on the customer’s behalf.')
                ->warning()
                ->send();

            return;
        }

        $this->form->getState();

        LegalAcceptance::accept($user, request()->ip(), request()->userAgent());

        Notification::make()
            ->title('Terms and Privacy accepted')
            ->success()
            ->send();

        $this->redirectIntended(Dashboard::getUrl(panel: 'app'));
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
            ->livewireSubmitHandler('accept')
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
        if (! $this->needsAcceptance() || TenantImpersonation::isActive()) {
            return [];
        }

        return [
            Action::make('accept')
                ->label('Accept')
                ->submit('accept'),
        ];
    }
}

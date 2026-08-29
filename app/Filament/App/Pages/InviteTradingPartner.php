<?php

namespace App\Filament\App\Pages;

use App\Enums\InboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Models\InboundConnection;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class InviteTradingPartner extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $navigationLabel = 'Invite partner';

    protected static ?string $title = 'Invite partner';

    protected static ?int $navigationSort = 11;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected string $view = 'filament.app.pages.invite-trading-partner';

    public string $name = '';

    public string $gln = '';

    public string $email = '';

    public string $transport = 'https';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'invite-partner';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavMasterData)
            && $user instanceof User
            && $user->can('create', TradingPartner::class)
            && $user->can('create', InboundConnection::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Create a trading partner and an inbound route. Trading Partners list is unchanged.';
    }

    public function invitePartnerAction(): Action
    {
        return Action::make('invitePartner')
            ->label('Invite partner')
            ->color('primary')
            ->action(function (): void {
                $this->authorize('create', TradingPartner::class);
                $this->authorize('create', InboundConnection::class);

                $this->validate([
                    'name' => ['required', 'string', 'max:255'],
                    'gln' => ['required', 'digits:13'],
                    'email' => ['required', 'email'],
                    'transport' => ['required', 'in:https,sftp'],
                ]);

                if (TradingPartner::query()->where('gln', $this->gln)->exists()) {
                    throw ValidationException::withMessages([
                        'gln' => 'A partner with this GLN already exists.',
                    ]);
                }

                $partner = TradingPartner::query()->create([
                    'name' => $this->name,
                    'gln' => $this->gln,
                    'email' => $this->email,
                    'partner_type' => PartnerType::Wholesaler,
                    'country_code' => 'US',
                    'is_active' => true,
                ]);

                $transport = InboundTransport::from($this->transport);

                InboundConnection::query()->create([
                    'name' => $partner->name.' inbound',
                    'serialization_provider' => $transport === InboundTransport::Sftp
                        ? SerializationProvider::CustomSftp
                        : SerializationProvider::CustomHttps,
                    'transport' => $transport,
                    'trading_partner_id' => $partner->getKey(),
                    'is_active' => $transport === InboundTransport::Https,
                ]);

                Notification::make()
                    ->title('Partner invited')
                    ->body($transport === InboundTransport::Sftp
                        ? 'Add SFTP host credentials on Inbound Connections.'
                        : 'HTTPS inbound token is ready on Inbound Connections.')
                    ->success()
                    ->send();

                $this->name = '';
                $this->gln = '';
                $this->email = '';
                $this->transport = 'https';
            });
    }

    public static function getDocumentation(): array|string
    {
        return 'integrations.partner-onboarding';
    }
}

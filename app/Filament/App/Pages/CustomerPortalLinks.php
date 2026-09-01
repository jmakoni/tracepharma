<?php

namespace App\Filament\App\Pages;

use App\Actions\Portal\EnsurePortalOrganization;
use App\Models\PortalUser;
use App\Models\TradingPartner;
use App\Models\User;
use App\Services\Outbound\CustomerPortalService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use RuntimeException;
use UnitEnum;

class CustomerPortalLinks extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Customer portal';

    protected static ?string $title = 'Customer portal';

    protected static ?int $navigationSort = 12;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected string $view = 'filament.app.pages.customer-portal-links';

    public ?string $issuedUrl = null;

    public ?int $issuedPartnerId = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'customer-portal';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return TenantFeatures::forTenant(tenant())->supportsMasterData()
            && JobRoleAccess::allows(Permissions::NavMasterData)
            && $user instanceof User
            && $user->can('deleteAny', TradingPartner::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (TenantFeatures::forTenant(tenant())->supportsClientPortalV2()) {
            return 'OTP client portal invites (email login codes) plus legacy signed download links. Separate from the supplier exception portal.';
        }

        return 'Issue a signed buyer download link. Separate from the supplier exception portal. Trading Partners is unchanged.';
    }

    /**
     * @return Collection<int, TradingPartner>
     */
    public function partners(): Collection
    {
        return TradingPartner::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'gln', 'email', 'customer_portal_uuid']);
    }

    public function supportsClientPortalV2(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsClientPortalV2();
    }

    protected function getHeaderActions(): array
    {
        if (! $this->supportsClientPortalV2()) {
            return [];
        }

        return [
            Action::make('inviteToClientPortal')
                ->label('Invite to client portal')
                ->color('primary')
                ->form([
                    Select::make('partner_id')
                        ->label('Trading partner')
                        ->options(fn (): array => $this->partners()->mapWithKeys(
                            fn (TradingPartner $partner): array => [
                                (int) $partner->getKey() => (string) $partner->name,
                            ],
                        )->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('email')
                        ->label('Invite email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $partner = TradingPartner::query()
                        ->where('is_active', true)
                        ->whereKey((int) ($data['partner_id'] ?? 0))
                        ->first();

                    if ($partner === null) {
                        Notification::make()->title('Partner not found')->danger()->send();

                        return;
                    }

                    $this->authorize('managePortalLink', $partner);

                    $email = strtolower(trim((string) ($data['email'] ?? '')));
                    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        Notification::make()->title('Invalid email')->danger()->send();

                        return;
                    }

                    $org = app(EnsurePortalOrganization::class)->handle($partner);
                    $portalUser = PortalUser::query()->firstOrCreate(
                        ['email' => $email],
                        ['is_active' => true],
                    );

                    if ($portalUser->organizations()->where('portal_organizations.id', $org->getKey())->exists()) {
                        Notification::make()
                            ->title('Already a member')
                            ->body($email.' is already linked to '.$partner->name.'.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $role = $org->users()->count() === 0 ? 'admin' : 'member';
                    $org->users()->attach($portalUser->getKey(), ['role' => $role]);

                    Notification::make()
                        ->title('Client portal invite ready')
                        ->body($email.' can sign in at /client-portal with an email OTP (role: '.$role.').')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function issueLinkAction(): Action
    {
        return Action::make('issueLink')
            ->label('Issue link')
            ->color('primary')
            ->action(function (array $arguments): void {
                $partner = TradingPartner::query()
                    ->where('is_active', true)
                    ->whereKey((int) ($arguments['partner'] ?? 0))
                    ->first();

                if ($partner === null) {
                    Notification::make()->title('Partner not found')->danger()->send();

                    return;
                }

                $this->authorize('managePortalLink', $partner);

                try {
                    $this->issuedUrl = app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);
                    $this->issuedPartnerId = (int) $partner->getKey();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not issue link')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Customer portal link ready')
                    ->body('Copy the signed URL below. It is not the supplier exception portal.')
                    ->success()
                    ->send();
            });
    }

    public static function getDocumentation(): array|string
    {
        return 'master-data.customer-portal';
    }
}

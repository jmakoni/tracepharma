<?php

namespace App\Filament\App\Pages;

use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\SanctumAbilities;
use App\Support\TenantFeatures;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

class ApiTokens extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'API Tokens';

    protected static ?string $title = 'API Tokens';

    protected static ?int $navigationSort = 20;

    protected static string|UnitEnum|null $navigationGroup = 'Integrations';

    protected string $view = 'filament.app.pages.api-tokens';

    public ?string $plainTextToken = null;

    public static function canAccess(): bool
    {
        $features = TenantFeatures::forTenant(tenant());

        if (! $features->supportsInboundIntegrations() && ! $features->supportsOutboundIntegrations()) {
            return false;
        }

        return JobRoleAccess::allows(Permissions::NavIntegrations)
            && (Auth::user()?->can(Permissions::UsersManage) || Auth::user()?->hasRole('owner'));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Create and revoke Sanctum tokens for programmatic access to the tenant API. '
            .'Tokens expire after '.$this->defaultTokenExpiryDays().' days by default.';
    }

    public function apiBaseUrl(): string
    {
        return url('/api');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tokenQuery())
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('abilities')
                    ->badge()
                    ->formatStateUsing(function (mixed $state): string {
                        $abilities = is_array($state)
                            ? $state
                            : json_decode(is_string($state) ? $state : '', true);

                        return (string) count(is_array($abilities) ? $abilities : []);
                    }),
            ])
            ->headerActions([
                Action::make('createDispenseCheckToken')
                    ->label('Create dispense-check token')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('gray')
                    ->visible(fn (): bool => TenantFeatures::forTenant(tenant())->supportsVrs())
                    ->schema([
                        TextInput::make('token_name')
                            ->label('Token name')
                            ->default('PMS dispense-check')
                            ->required()
                            ->maxLength(255),
                        DatePicker::make('expires_at')
                            ->label('Expiry date')
                            ->default(now()->addDays($this->defaultTokenExpiryDays())->toDateString())
                            ->minDate(now())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = Auth::user();

                        if ($user === null) {
                            return;
                        }

                        $expiresAt = Carbon::createFromFormat(
                            'Y-m-d',
                            $data['expires_at'] ?? now()->addDays($this->defaultTokenExpiryDays())->toDateString(),
                        );

                        $this->plainTextToken = $user->createToken(
                            $data['token_name'],
                            [SanctumAbilities::VRS_DISPENSE_CHECK],
                            $expiresAt,
                        )->plainTextToken;

                        Notification::make()
                            ->title('Dispense-check token created')
                            ->body('Copy the token now — it will not be shown again. Ability: vrs:dispense-check.')
                            ->success()
                            ->send();
                    }),
                Action::make('createToken')
                    ->label('Create token')
                    ->schema([
                        TextInput::make('token_name')
                            ->label('Token name')
                            ->required()
                            ->maxLength(255),
                        CheckboxList::make('abilities')
                            ->label('Abilities')
                            ->options(SanctumAbilities::options())
                            ->columns(2)
                            ->default(fn (): array => $this->defaultAbilitiesFromRequest())
                            ->required()
                            ->minItems(1),
                        DatePicker::make('expires_at')
                            ->label('Expiry date')
                            ->default(now()->addDays($this->defaultTokenExpiryDays())->toDateString())
                            ->minDate(now())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = Auth::user();

                        if ($user === null) {
                            return;
                        }

                        try {
                            $abilities = SanctumAbilities::validateForTokenCreation($data['abilities'] ?? []);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Invalid token abilities')
                                ->body(collect($exception->errors())->flatten()->first() ?? 'Select valid abilities.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $expiresAt = Carbon::createFromFormat(
                            'Y-m-d',
                            $data['expires_at'] ?? now()->addDays($this->defaultTokenExpiryDays())->toDateString(),
                        );

                        $this->plainTextToken = $user->createToken(
                            $data['token_name'],
                            $abilities,
                            $expiresAt,
                        )->plainTextToken;

                        Notification::make()
                            ->title('API token created')
                            ->body('Copy the token now — it will not be shown again.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Revoke'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No API tokens')
            ->emptyStateDescription('Create a token to authenticate REST API requests.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.app.partials.api-tokens-header'),
                EmbeddedTable::make(),
            ]);
    }

    private function tokenQuery(): Builder
    {
        $user = Auth::user();

        return PersonalAccessToken::query()
            ->where('tokenable_id', $user?->getKey())
            ->where('tokenable_type', $user?->getMorphClass());
    }

    private function defaultAbilitiesFromRequest(): array
    {
        $ability = request()->query('ability');

        if (! is_string($ability) || $ability === '') {
            return [];
        }

        $options = array_keys(SanctumAbilities::options());

        return in_array($ability, $options, true) ? [$ability] : [];
    }

    private function defaultTokenExpiryDays(): int
    {
        $minutes = config('sanctum.expiration');

        if (! is_numeric($minutes) || (int) $minutes <= 0) {
            return 90;
        }

        return (int) max(1, round(((int) $minutes) / 60 / 24));
    }
}

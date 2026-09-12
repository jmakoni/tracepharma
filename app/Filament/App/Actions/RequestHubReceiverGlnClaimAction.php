<?php

declare(strict_types=1);

namespace App\Filament\App\Actions;

use App\Actions\Integrations\RequestHubReceiverGlnClaim;
use App\Enums\SerializationProvider;
use App\Filament\App\Resources\InboundConnections\InboundConnectionResource;
use App\Filament\Notifications\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EpcisHub\ClaimableReceiverGlns;
use App\Support\EpcisHub\EpcisHubPlatformConfig;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class RequestHubReceiverGlnClaimAction
{
    public const NAME = 'requestHubReceiverGlnClaim';

    public const APPROVAL_COPY = 'Binding a site GLN or inbound connection is not a hub claim until platform Admin approves.';

    public static function forSiteGln(mixed $gln): Action
    {
        return self::make(
            fixedGln: ClaimableReceiverGlns::normalizeStoredGln($gln),
            selectGln: false,
        );
    }

    public static function withGlnSelect(): Action
    {
        return self::make(fixedGln: null, selectGln: true);
    }

    private static function make(?string $fixedGln, bool $selectGln): Action
    {
        $schema = [
            Select::make('provider')
                ->label('Hub provider')
                ->options(fn (): array => self::providerOptions())
                ->required()
                ->native(false),
        ];

        if ($selectGln) {
            $schema[] = Select::make('gln')
                ->label('Receiver GLN')
                ->options(fn (): array => self::claimableGlnOptions())
                ->required()
                ->searchable()
                ->native(false);
        }

        $schema[] = Textarea::make('reason')
            ->label('Reason')
            ->required()
            ->rows(4)
            ->maxLength(2000)
            ->helperText(self::APPROVAL_COPY);

        return Action::make(self::NAME)
            ->label('Request hub receiver GLN claim')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('primary')
            ->modalHeading('Request hub receiver GLN claim')
            ->modalDescription(self::APPROVAL_COPY)
            ->modalSubmitActionLabel('Submit request')
            ->visible(fn (): bool => self::canRequest($fixedGln, $selectGln))
            ->schema($schema)
            ->action(function (array $data) use ($fixedGln, $selectGln): void {
                abort_unless(self::canRequest($fixedGln, $selectGln), 403);

                $tenant = tenant();
                $user = auth()->user();
                abort_unless($tenant instanceof Tenant && $user instanceof User, 403);

                $gln = $selectGln ? (string) ($data['gln'] ?? '') : (string) $fixedGln;

                try {
                    app(RequestHubReceiverGlnClaim::class)->request(
                        tenant: $tenant,
                        provider: (string) ($data['provider'] ?? ''),
                        gln: $gln,
                        reason: (string) ($data['reason'] ?? ''),
                        requestingUser: $user,
                    );
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        $selectGln ? 'gln' : 'provider' => $exception->getMessage(),
                    ]);
                } finally {
                    self::restoreTenantContext($tenant);
                }

                Notification::make()
                    ->title('Hub receiver GLN claim requested')
                    ->body('Platform Admin approval is required before this GLN is claimed.')
                    ->success()
                    ->send();
            });
    }

    private static function canRequest(?string $fixedGln, bool $selectGln): bool
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant
            || ! TenantFeatures::forTenant($tenant)->supportsInboundIntegrations()
            || ! InboundConnectionResource::canCreate()) {
            return false;
        }

        if ($selectGln) {
            return self::claimableGlnOptions() !== [] && self::providerOptions() !== [];
        }

        return $fixedGln !== null
            && array_key_exists($fixedGln, self::claimableGlnOptions())
            && self::providerOptions() !== [];
    }

    /**
     * @return array<string, string>
     */
    private static function claimableGlnOptions(): array
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        try {
            return ClaimableReceiverGlns::options($tenant);
        } finally {
            self::restoreTenantContext($tenant);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function providerOptions(): array
    {
        $tenant = tenant();
        if (! $tenant instanceof Tenant || ! is_string($tenant->inbound_environment)) {
            return [];
        }

        $configured = is_array($tenant->hub_providers) ? $tenant->hub_providers : [];
        $enabled = app(EpcisHubPlatformConfig::class)->enabledProviders($tenant->inbound_environment);
        $options = [];

        foreach ($configured as $provider) {
            $slug = is_string($provider) ? strtolower(trim($provider)) : '';
            if ($slug === '' || ! in_array($slug, $enabled, true)) {
                continue;
            }

            $options[$slug] = SerializationProvider::tryFrom($slug)?->label()
                ?? str($slug)->headline()->toString();
        }

        return $options;
    }

    private static function restoreTenantContext(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            tenancy()->initialize($tenant);
        }
    }
}

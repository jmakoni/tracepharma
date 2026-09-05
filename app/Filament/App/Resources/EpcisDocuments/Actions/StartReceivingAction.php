<?php

namespace App\Filament\App\Resources\EpcisDocuments\Actions;

use App\Actions\Receiving\OpenReceivingSessionFromDocument;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Epcis\EpcisDocument;
use App\Models\Receiving\ReceivingSession;
use App\Services\Receiving\ReceivingGate;
use App\Support\Auth\CurrentSite;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use App\Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Shared Start receiving / Scan in wiring: optional receive-site Select when
 * the tenant has more than one active site with a GLN.
 */
final class StartReceivingAction
{
    /**
     * Apply modal + site Select when multiple eligible sites exist.
     *
     * @param  (callable(): EpcisDocument)|null  $document  Explicit resolver (view page); table uses action record.
     */
    public static function configure(Action $action, ?callable $document = null): Action
    {
        $resolveDocument = function (Action $action) use ($document): ?EpcisDocument {
            if ($document !== null) {
                return $document();
            }

            $record = $action->getRecord();

            return $record instanceof EpcisDocument ? $record : null;
        };

        $needsSiteChoice = function (): bool {
            try {
                return EligibleReceiveSites::requiresChoice();
            } catch (\Throwable $e) {
                report($e);

                return false;
            }
        };

        return $action
            ->modal(fn (): bool => $needsSiteChoice())
            ->modalHeading('Choose receive site')
            ->modalDescription('Receiving events will use this site’s GLN as read point and business location.')
            ->modalSubmitActionLabel('Start receiving')
            ->fillForm(function (Action $action) use ($resolveDocument, $needsSiteChoice): array {
                if (! $needsSiteChoice()) {
                    return [];
                }

                $doc = $resolveDocument($action);
                $options = EligibleReceiveSites::options();
                $fallback = $doc !== null ? EligibleReceiveSites::defaultSiteId($doc) : null;

                return [
                    'site_id' => CurrentSite::preferredId($fallback, $options),
                ];
            })
            ->schema(function (Action $action) use ($resolveDocument, $needsSiteChoice): array {
                if (! $needsSiteChoice()) {
                    return [];
                }

                $doc = $resolveDocument($action);

                return [
                    Select::make('site_id')
                        ->label('Receive site')
                        ->options(fn (): array => EligibleReceiveSites::options())
                        ->default(fn (): ?int => CurrentSite::preferredId(
                            $doc !== null ? EligibleReceiveSites::defaultSiteId($doc) : null,
                            EligibleReceiveSites::options(),
                        ))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('Defaults to the site chooser’s current site when valid, otherwise ship-to site or Organization default receive site when set.'),
                ];
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveSiteId(array $data): ?int
    {
        if (! isset($data['site_id']) || blank($data['site_id'])) {
            return null;
        }

        return (int) $data['site_id'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function open(
        EpcisDocument $document,
        array $data = [],
        ?int $openedBy = null,
    ): ReceivingSession {
        return app(OpenReceivingSessionFromDocument::class)->handle(
            $document,
            siteId: self::resolveSiteId($data),
            openedBy: $openedBy,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function openOrNotify(
        EpcisDocument $document,
        array $data = [],
        ?int $openedBy = null,
    ): ?ReceivingSession {
        try {
            return self::open($document, $data, $openedBy);
        } catch (DomainException $e) {
            Notification::make()
                ->title('Cannot start receiving')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    public static function receivingUrl(ReceivingSession $session): string
    {
        return ReceivingSessionResource::getUrl('view', ['record' => $session], panel: 'app');
    }

    /**
     * Table row action: Scan in / Continue receiving.
     */
    public static function forTable(): Action
    {
        return self::configure(
            Action::make('scanIn')
                ->label(fn (EpcisDocument $record): string => $record->openReceivingSession() !== null
                    ? 'Continue receiving'
                    : 'Scan in')
                ->icon(Heroicon::OutlinedQrCode)
                ->visible(fn (EpcisDocument $record): bool => self::canStartReceiving($record))
                ->disabled(fn (EpcisDocument $record): bool => app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($record) !== null)
                ->tooltip(function (EpcisDocument $record): ?string {
                    $blocking = app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($record);
                    if ($blocking === null) {
                        return null;
                    }

                    return 'Blocked by open document-wide exception #'.$blocking->getKey()
                        .' ('.($blocking->type?->name ?? 'exception').').';
                })
                ->action(function (EpcisDocument $record, array $data): RedirectResponse|Redirector|null {
                    $session = self::openOrNotify($record, $data, auth()->id());

                    if ($session === null) {
                        return null;
                    }

                    return redirect(self::receivingUrl($session));
                }),
        );
    }

    /**
     * View-page header action: Start receiving / Continue receiving.
     *
     * @param  callable(): EpcisDocument  $document
     * @param  callable(ReceivingSession): void  $onOpened
     */
    public static function forView(callable $document, callable $onOpened): Action
    {
        return self::configure(
            Action::make('startReceiving')
                ->label(fn (): string => $document()->openReceivingSession() !== null
                    ? 'Continue receiving'
                    : 'Start receiving')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('success')
                ->visible(function () use ($document): bool {
                    if (! TenantFeatures::forTenant(tenant())->supportsReceiving()) {
                        return false;
                    }

                    return self::canStartReceiving($document());
                })
                ->disabled(function () use ($document): bool {
                    return app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($document()) !== null;
                })
                ->tooltip(function () use ($document): ?string {
                    $blocking = app(ReceivingGate::class)->documentBlockedAfterDestinationRecheck($document());
                    if ($blocking === null) {
                        return null;
                    }

                    return 'Blocked by open document-wide exception #'.$blocking->getKey()
                        .' ('.($blocking->type?->name ?? 'exception').'). Resolve it before receiving.';
                })
                ->action(function (array $data) use ($document, $onOpened): void {
                    $session = self::openOrNotify($document(), $data, auth()->id());

                    if ($session === null) {
                        return;
                    }

                    $onOpened($session);
                }),
            $document,
        );
    }

    private static function canStartReceiving(EpcisDocument $document): bool
    {
        if ($document->isFloorReceived()) {
            return false;
        }

        $requireValidated = (bool) config('tracepharma.epcis.require_validated_for_receiving', true);

        return $requireValidated
            ? $document->status === 'validated'
            : in_array($document->status, ['parsed', 'validated'], true);
    }
}

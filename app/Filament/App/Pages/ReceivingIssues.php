<?php

namespace App\Filament\App\Pages;

use App\Actions\Receiving\FlagManualReceivingException;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use UnitEnum;

class ReceivingIssues extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Receiving issues';

    protected static ?string $title = 'Receiving issues';

    protected static ?int $navigationSort = 10;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected string $view = 'filament.app.pages.receiving-issues';

    #[Url(as: 'session')]
    public ?int $sessionId = null;

    public string $notes = '';

    /** @var list<int> */
    public array $damagedEpcIds = [];

    public static function getSlug(?Panel $panel = null): string
    {
        return 'receiving-issues';
    }

    public static function canAccess(): bool
    {
        return (TenantFeatures::forTenant(tenant())->supportsReceiving())
            && JobRoleAccess::allows(Permissions::NavReceive);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function urlForSession(int $sessionId): string
    {
        return static::getUrl(['session' => $sessionId], panel: 'app');
    }

    public function mount(): void
    {
        // Prefer Livewire #[Url] binding; fall back to request query for non-Livewire entry.
        $sessionId = $this->sessionId ?? request()->integer('session');

        if ($sessionId > 0) {
            $this->loadSession((int) $sessionId);
        }
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Report shortage, overage, or damaged product after receive is complete. Scan HUD stays claim-free.';
    }

    public function selectSession(int|string|null $sessionId): void
    {
        $id = (int) $sessionId;

        if ($id <= 0) {
            $this->sessionId = null;
            $this->notes = '';
            $this->damagedEpcIds = [];

            return;
        }

        $this->loadSession($id);
    }

    private function loadSession(int $sessionId): void
    {
        $session = $this->sessionsQuery()
            ->whereKey($sessionId)
            ->first();

        if ($session === null) {
            Notification::make()
                ->title('Session not found')
                ->danger()
                ->send();

            $this->sessionId = null;

            return;
        }

        $this->sessionId = (int) $session->getKey();
        $this->notes = '';
        $this->damagedEpcIds = [];
        $session->loadMissing(['document', 'tradingPartner', 'site']);
    }

    /**
     * @return Builder<ReceivingSession>
     */
    private function sessionsQuery(): Builder
    {
        $query = ReceivingSession::query()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->latest('id');

        $user = auth()->user();

        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        if ($user->can(Permissions::SitesAccessAll)) {
            return $query;
        }

        return $query->whereIn('site_id', SiteAccess::userSiteIds($user));
    }

    public function session(): ?ReceivingSession
    {
        if ($this->sessionId === null) {
            return null;
        }

        // Always resolve through completed + site-scoped query — never unconstrained find($sessionId).
        return $this->sessionsQuery()
            ->with(['document', 'tradingPartner', 'site'])
            ->whereKey($this->sessionId)
            ->first();
    }

    /**
     * @return array<int, string>
     */
    public function completedSessionOptions(): array
    {
        return $this->sessionsQuery()
            ->with(['tradingPartner', 'site'])
            ->limit(50)
            ->get()
            ->mapWithKeys(function (ReceivingSession $session): array {
                $partner = $session->tradingPartner?->name ?? 'No partner';
                $site = $session->site?->name;
                $label = '#'.$session->getKey().' · '.$partner;
                if (filled($site)) {
                    $label .= ' · '.$site;
                }
                if ($session->completed_at !== null) {
                    $label .= ' · '.$session->completed_at->timezone(config('app.timezone'))->format('Y-m-d H:i');
                }

                return [(int) $session->getKey() => $label];
            })
            ->all();
    }

    public function shortageCount(): int
    {
        if ($this->sessionId === null) {
            return 0;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->sessionId)
            ->where('status', 'expected')
            ->count();
    }

    public function overageCount(): int
    {
        if ($this->sessionId === null) {
            return 0;
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->sessionId)
            ->where('status', 'unexpected')
            ->count();
    }

    /**
     * @return array<int, string>
     */
    public function damagedEpcOptions(): array
    {
        if ($this->sessionId === null) {
            return [];
        }

        return ReceivingScanLine::query()
            ->where('receiving_session_id', $this->sessionId)
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->whereNotNull('epc_id')
            ->with('epc')
            ->orderBy('id')
            ->get()
            ->unique('epc_id')
            ->mapWithKeys(function (ReceivingScanLine $line): array {
                $epc = $line->epc;
                if ($epc === null) {
                    return [];
                }

                $label = filled($epc->sscc18)
                    ? 'SSCC '.$epc->sscc18
                    : trim(($epc->gtin14 ?? '').' / '.($epc->serial_number ?? ''));

                if ($label === '/' || $label === '') {
                    $label = 'EPC #'.$epc->getKey();
                }

                return [(int) $epc->getKey() => $label.' ('.$line->status.')'];
            })
            ->all();
    }

    /**
     * @return Collection<int, ExceptionCase>
     */
    public function openCasesForSession(): Collection
    {
        $session = $this->session();

        if ($session === null) {
            return collect();
        }

        return ExceptionCase::query()
            ->open()
            ->with('type')
            ->whereHas('activities', function (Builder $activities) use ($session): void {
                $activities
                    ->where('meta->source', 'receiving_issues')
                    ->where(function (Builder $meta) use ($session): void {
                        $meta->where('meta->receiving_session_id', (int) $session->getKey())
                            ->orWhere('meta->receiving_session_id', (string) $session->getKey());
                    });
            })
            ->latest('id')
            ->limit(15)
            ->get();
    }

    public function sessionViewUrl(): ?string
    {
        $session = $this->session();

        if ($session === null) {
            return null;
        }

        return ReceivingSessionResource::getUrl('view', ['record' => $session], panel: 'app');
    }

    public function exceptionUrl(ExceptionCase $case): ?string
    {
        if (! ExceptionResource::canAccess()) {
            return null;
        }

        return ExceptionResource::getUrl('view', ['record' => $case], panel: 'app');
    }

    protected function getHeaderActions(): array
    {
        return [
            RegulatoryCompliance::apply(
                Action::make('reportShortage')
                    ->label('Report shortage')
                    ->icon(Heroicon::OutlinedMinusCircle)
                    ->color('warning')
                    ->visible(fn (): bool => $this->session() !== null)
                    ->disabled(fn (): bool => $this->shortageCount() === 0)
                    ->requiresConfirmation()
                    ->modalHeading('Report shortage?')
                    ->modalDescription('Opens a PARTIAL_SHIPMENT_UNDECLARED exception for expected lines that were not confirmed.')
                    ->modalSubmitActionLabel('File shortage')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (array $data): void {
                        $this->fileIssue('shortage', [
                            'notes' => $data['notes'] ?? $this->notes,
                        ]);
                    }),
                'receiving_issue_shortage',
                requireReason: true,
                subject: fn () => $this->session(),
            ),
            RegulatoryCompliance::apply(
                Action::make('reportOverage')
                    ->label('Report overage')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->color('danger')
                    ->visible(fn (): bool => $this->session() !== null)
                    ->disabled(fn (): bool => $this->overageCount() === 0)
                    ->requiresConfirmation()
                    ->modalHeading('Report overage?')
                    ->modalDescription('Opens an OVER_SHIPMENT exception for unexpected scan lines on this session.')
                    ->modalSubmitActionLabel('File overage')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->maxLength(2000),
                    ])
                    ->action(function (array $data): void {
                        $this->fileIssue('overage', [
                            'notes' => $data['notes'] ?? $this->notes,
                        ]);
                    }),
                'receiving_issue_overage',
                requireReason: true,
                subject: fn () => $this->session(),
            ),
            RegulatoryCompliance::apply(
                Action::make('reportDamaged')
                    ->label('Report damaged')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('danger')
                    ->visible(fn (): bool => $this->session() !== null)
                    ->disabled(fn (): bool => $this->damagedEpcOptions() === [])
                    ->requiresConfirmation()
                    ->modalHeading('Report damaged product?')
                    ->modalDescription('Opens a SUSPECT_PRODUCT case and quarantine hold(s) for the selected EPC(s).')
                    ->modalSubmitActionLabel('File damaged')
                    ->schema(fn (): array => [
                        CheckboxList::make('epc_ids')
                            ->label('Damaged units / packs')
                            ->options(fn (): array => $this->damagedEpcOptions())
                            ->required()
                            ->columns(1)
                            ->bulkToggleable(),
                        Textarea::make('notes')
                            ->label('Damage notes')
                            ->rows(3)
                            ->maxLength(2000)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->fileIssue('damaged', [
                            'epc_ids' => array_values(array_map('intval', (array) ($data['epc_ids'] ?? []))),
                            'notes' => $data['notes'] ?? $this->notes,
                        ]);
                    }),
                'receiving_issue_damaged',
                requireReason: true,
                subject: fn () => $this->session(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fileIssue(string $type, array $payload): void
    {
        $session = $this->session();

        if ($session === null) {
            Notification::make()
                ->title('Select a completed session first')
                ->danger()
                ->send();

            return;
        }

        try {
            $case = app(FlagManualReceivingException::class)->execute(
                $session,
                $type,
                $payload,
                auth()->user(),
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title('Could not file issue')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->notes = '';
        $this->damagedEpcIds = [];

        $notification = Notification::make()
            ->title(match ($type) {
                'shortage' => 'Shortage reported',
                'overage' => 'Overage reported',
                'damaged' => 'Damaged product reported',
                default => 'Receiving issue filed',
            })
            ->body('Exception case #'.$case->getKey().' opened.')
            ->success();

        if (ExceptionResource::canAccess()) {
            $notification->actions([
                Action::make('open')
                    ->label('Open exception')
                    ->url(ExceptionResource::getUrl('view', ['record' => $case], panel: 'app')),
            ]);
        }

        $notification->send();
    }
}

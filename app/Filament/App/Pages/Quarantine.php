<?php

namespace App\Filament\App\Pages;

use App\Actions\Quarantine\ReleaseQuarantineHold;
use App\Enums\ExceptionDisposition;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Filament\Support\RegulatoryCompliance;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class Quarantine extends Page implements HasKnowledgeBase
{
    private const HOLDS_PER_PAGE = 25;

    private const INVESTIGATIONS_PER_PAGE = 25;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationLabel = 'Quarantine';

    protected static ?string $title = 'Quarantine workstation';

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.app.pages.quarantine';

    public string $filter = '';

    public ?int $siteId = null;

    public int $holdsPage = 1;

    public int $investigationsPage = 1;

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsComplianceCases()
            && JobRoleAccess::allows(Permissions::NavExceptions);
    }

    public function mount(): void
    {
        // Default to all accessible sites so document-less find-recall holds remain visible.
        $this->siteId = null;
    }

    public function updatedFilter(): void
    {
        $this->holdsPage = 1;
    }

    public function updatedSiteId(): void
    {
        $this->holdsPage = 1;
        $this->investigationsPage = 1;
    }

    /**
     * @return array<int|string, string>
     */
    public function siteFilterOptions(): array
    {
        return EligibleReceiveSites::options();
    }

    /**
     * @return Collection<int, QuarantineHold>
     */
    public function openHolds(): Collection
    {
        return $this->openHoldsQuery()
            ->with(['epc', 'exception.type', 'document'])
            ->latest('opened_at')
            ->forPage($this->holdsPage, self::HOLDS_PER_PAGE)
            ->get();
    }

    public function openHoldsTotal(): int
    {
        return $this->openHoldsQuery()->count();
    }

    public function holdsLastPage(): int
    {
        return max(1, (int) ceil($this->openHoldsTotal() / self::HOLDS_PER_PAGE));
    }

    public function nextHoldsPage(): void
    {
        $this->holdsPage = min($this->holdsPage + 1, $this->holdsLastPage());
    }

    public function previousHoldsPage(): void
    {
        $this->holdsPage = max(1, $this->holdsPage - 1);
    }

    /**
     * @return Collection<int, ExceptionCase>
     */
    public function openInvestigations(): Collection
    {
        return $this->openInvestigationsQuery()
            ->with(['type', 'document'])
            ->latest('id')
            ->forPage($this->investigationsPage, self::INVESTIGATIONS_PER_PAGE)
            ->get();
    }

    public function openInvestigationsTotal(): int
    {
        return $this->openInvestigationsQuery()->count();
    }

    public function investigationsLastPage(): int
    {
        return max(1, (int) ceil($this->openInvestigationsTotal() / self::INVESTIGATIONS_PER_PAGE));
    }

    public function nextInvestigationsPage(): void
    {
        $this->investigationsPage = min($this->investigationsPage + 1, $this->investigationsLastPage());
    }

    public function previousInvestigationsPage(): void
    {
        $this->investigationsPage = max(1, $this->investigationsPage - 1);
    }

    public function exceptionsUrl(): string
    {
        return ExceptionResource::getUrl('index', panel: 'app');
    }

    public function exceptionUrl(int $id): string
    {
        return ExceptionResource::getUrl('view', ['record' => $id], panel: 'app');
    }

    public function canRelease(): bool
    {
        return Auth::check()
            && JobRoleAccess::allows(Permissions::NavExceptions);
    }

    public function canReleaseHold(QuarantineHold $hold): bool
    {
        if (! $this->canRelease()) {
            return false;
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        $shipToSiteId = $hold->document?->ship_to_site_id !== null
            ? (int) $hold->document->ship_to_site_id
            : null;

        if (! SiteAccess::canAccessShipToSite($user, $shipToSiteId)) {
            return false;
        }

        if ($hold->exception?->disposition === ExceptionDisposition::Illegitimate) {
            return false;
        }

        return true;
    }

    public function releaseHoldAction(): Action
    {
        return RegulatoryCompliance::apply(
            Action::make('releaseHold')
                ->label('Release hold')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Release from quarantine')
                ->modalDescription('Confirm QA review is complete before releasing this unit.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Release reason')
                        ->required()
                        ->rows(3)
                        ->default('Released after QA review'),
                ])
                ->action(function (array $data, array $arguments): void {
                    abort_unless($this->canRelease(), 403);

                    $user = Auth::user();
                    abort_unless($user instanceof User, 403);

                    $holdId = (int) ($arguments['hold'] ?? 0);
                    $hold = $this->openHoldsQuery()
                        ->with(['exception', 'document'])
                        ->find($holdId);

                    if ($hold === null) {
                        Notification::make()
                            ->title('Hold not available')
                            ->body('Only open quarantine holds can be released.')
                            ->warning()
                            ->send();

                        return;
                    }

                    if (! $this->canReleaseHold($hold)) {
                        Notification::make()
                            ->title('Not authorized')
                            ->body('You do not have access to release this hold.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $released = app(ReleaseQuarantineHold::class)->handle(
                        $hold,
                        (string) ($data['reason'] ?? 'Released after QA review'),
                        $user,
                    );

                    if ($released->fresh()->status === 'open') {
                        Notification::make()
                            ->title('Hold kept open')
                            ->body('Another open or illegitimate case still references this unit.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Hold released')
                        ->body('Document the decision in your SOP log.')
                        ->success()
                        ->send();
                }),
            'quarantine_workstation_release',
            requireReason: true,
            existingReasonField: 'reason',
        );
    }

    /**
     * @return Builder<QuarantineHold>
     */
    private function openHoldsQuery(): Builder
    {
        $query = QuarantineHold::query()->open();
        $query = SiteAccess::constrainExceptionCaseRelation($query, 'exception');
        $query = $this->applyOptionalSiteFilter($query);

        $filter = trim($this->filter);
        if ($filter !== '') {
            $like = '%'.$filter.'%';
            $query->where(function ($builder) use ($like): void {
                $builder
                    ->where('reason', 'like', $like)
                    ->orWhereHas('epc', function ($epc) use ($like): void {
                        $epc->where('gtin14', 'like', $like)
                            ->orWhere('serial_number', 'like', $like)
                            ->orWhere('epc_uri', 'like', $like);
                    })
                    ->orWhereHas('exception', function ($exception) use ($like): void {
                        $exception->where('title', 'like', $like)
                            ->orWhere('description', 'like', $like);
                    });
            });
        }

        return $query;
    }

    /**
     * @return Builder<ExceptionCase>
     */
    private function openInvestigationsQuery(): Builder
    {
        $query = ExceptionCase::query()
            ->open()
            ->withOpenQuarantine();
        $query = SiteAccess::constrainExceptionCases($query);

        return $this->applyOptionalSiteFilter($query);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyOptionalSiteFilter(Builder $query): Builder
    {
        if ($this->siteId === null) {
            return $query;
        }

        $user = Auth::user();
        if ($user instanceof User
            && ! $user->can(Permissions::SitesAccessAll)
            && ! SiteAccess::canAccessSite($user, $this->siteId)
        ) {
            return $query->whereRaw('0 = 1');
        }

        $siteId = $this->siteId;

        return $query->where(function (Builder $outer) use ($siteId): void {
            $model = $outer->getModel();

            if ($model instanceof ExceptionCase) {
                $outer->where('site_id', $siteId)
                    ->orWhereHas(
                        'document',
                        fn (Builder $document): Builder => $document->where('ship_to_site_id', $siteId),
                    );

                return;
            }

            $outer->whereHas(
                'exception',
                function (Builder $exception) use ($siteId): void {
                    $exception->where('site_id', $siteId)
                        ->orWhereHas(
                            'document',
                            fn (Builder $document): Builder => $document->where('ship_to_site_id', $siteId),
                        );
                },
            )->orWhereHas(
                'document',
                fn (Builder $document): Builder => $document->where('ship_to_site_id', $siteId),
            );
        });
    }

    public static function getDocumentation(): array|string
    {
        return 'compliance.quarantine';
    }
}

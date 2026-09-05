<?php

namespace App\Filament\App\Pages;

use App\Actions\Exceptions\StartInvestigatorSla;
use App\Enums\ExceptionReceiveImpact;
use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Exceptions\InvestigatorSlaClock;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

class InvestigatorSla extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Investigator SLA';

    protected static ?string $title = 'Investigator SLA';

    protected static ?int $navigationSort = 16;

    protected static string|UnitEnum|null $navigationGroup = 'Receiving';

    protected string $view = 'filament.app.pages.investigator-sla';

    public static function getSlug(?Panel $panel = null): string
    {
        return 'investigator-sla';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsInboundIntegrations()
            && JobRoleAccess::allows(Permissions::NavExceptions);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return '72-hour supplier correction clock. Emails the existing exception portal. Exceptions list is unchanged.';
    }

    /**
     * @return Collection<int, ExceptionCase>
     */
    public function blockingCases(): Collection
    {
        return $this->casesQuery()
            ->with([
                'type',
                'tradingPartner',
                'activities' => fn ($query) => app(InvestigatorSlaClock::class)
                    ->constrainSupplierEmailActivities($query),
            ])
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(100)
            ->get();
    }

    public function clockLabel(ExceptionCase $case): string
    {
        return app(InvestigatorSlaClock::class)->remainingLabel($case);
    }

    public function clockBreached(ExceptionCase $case): bool
    {
        return app(InvestigatorSlaClock::class)->isBreached($case);
    }

    public function lastEmailLabel(ExceptionCase $case): string
    {
        $clock = app(InvestigatorSlaClock::class);

        $activity = $case->relationLoaded('activities')
            ? $case->activities->first(fn (mixed $activity): bool => $clock->isSupplierEmailActivity($activity))
            : $clock->constrainSupplierEmailActivities(
                ExceptionActivity::query()->where('exception_id', $case->getKey()),
            )->latest('id')->first();

        if ($activity === null) {
            return 'Not emailed';
        }

        return 'Emailed '.$activity->created_at?->diffForHumans();
    }

    public function exceptionUrl(ExceptionCase $case): string
    {
        return ExceptionResource::getUrl('view', ['record' => $case], panel: 'app');
    }

    public function emailSupplierAction(): Action
    {
        return Action::make('emailSupplier')
            ->label('Email supplier')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Email the supplier portal link?')
            ->modalDescription('Starts the 72-hour clock if needed and sends the existing DSCSA exception email.')
            ->action(function (array $arguments): void {
                $caseId = (int) ($arguments['case'] ?? 0);
                $case = $this->casesQuery()->whereKey($caseId)->first();

                if ($case === null) {
                    Notification::make()
                        ->title('Case not found')
                        ->danger()
                        ->send();

                    return;
                }

                $actor = auth()->user();
                if ($actor === null) {
                    return;
                }

                $result = app(StartInvestigatorSla::class)->handle($case, $actor);

                if (! ($result['sent'] ?? false)) {
                    Notification::make()
                        ->title('Email not sent')
                        ->body((string) ($result['error'] ?? 'Supplier email failed.'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Supplier emailed')
                    ->body('Portal link sent. 72-hour clock is running.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return Builder<ExceptionCase>
     */
    private function casesQuery(): Builder
    {
        return SiteAccess::constrainExceptionCases(
            ExceptionCase::query()
                ->open()
                ->whereHas('type', function (Builder $query): void {
                    $query->whereIn('receive_impact', [
                        ExceptionReceiveImpact::HardBlocking->value,
                        ExceptionReceiveImpact::BusinessRule->value,
                    ]);
                }),
        );
    }

    public static function getDocumentation(): array|string
    {
        return 'exceptions.investigator-sla';
    }
}

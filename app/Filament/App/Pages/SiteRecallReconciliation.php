<?php

namespace App\Filament\App\Pages;

use App\Models\Epcis\Epc;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Recalls\OpenRecallFlag;
use App\Support\Recalls\OpenRecallHits;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use App\Support\Tracing\Gs1DualDisplay;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

class SiteRecallReconciliation extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Site recall';

    protected static ?string $title = 'Site recall';

    protected static ?int $navigationSort = 18;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.site-recall-reconciliation';

    public ?int $siteId = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'site-recall';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsTracingRequests()
            && JobRoleAccess::allows(Permissions::NavCompliance);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->siteId = CurrentSite::id()
            ?? array_key_first(EligibleReceiveSites::options($this->authUser()));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'On-hand serials that match an open recall. Find/Recall and Quarantine stay as they are.';
    }

    /**
     * @return array<int, string>
     */
    public function siteOptions(): array
    {
        return EligibleReceiveSites::options($this->authUser());
    }

    /**
     * @return Collection<int, Epc>
     */
    public function rows(): Collection
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            return collect();
        }

        return app(OpenRecallHits::class)->epcsAtSite($siteId)
            ->reject(fn (Epc $epc): bool => $this->isAccounted($epc, $siteId))
            ->values();
    }

    public function isTruncated(): bool
    {
        $siteId = $this->resolvedSiteId();
        if ($siteId === null) {
            return false;
        }

        return app(OpenRecallHits::class)->isTruncated($siteId);
    }

    public function identifier(Epc $epc): string
    {
        return Gs1DualDisplay::forEpc($epc)['primary'];
    }

    public function canQuarantine(): bool
    {
        return JobRoleAccess::allows(Permissions::NavExceptions);
    }

    public function quarantineHitAction(): Action
    {
        return Action::make('quarantineHit')
            ->label('Quarantine')
            ->color('danger')
            ->visible(fn (): bool => $this->canQuarantine())
            ->action(function (array $arguments): void {
                $epcId = (int) ($arguments['epc'] ?? 0);
                $siteId = $this->resolvedSiteId();
                if ($epcId < 1 || $siteId === null || ! $this->canQuarantine()) {
                    return;
                }

                $epc = Epc::query()->find($epcId);
                if (! $epc instanceof Epc || ! $this->isCurrentSiteHit($epc, $siteId)) {
                    Notification::make()->title('Not a hit at this site')->danger()->send();

                    return;
                }

                $case = app(QuarantineService::class)->quarantineFromFindRecall(
                    [$epcId],
                    'Site recall reconciliation',
                    $this->authUser(),
                );
                $case->forceFill(['site_id' => $siteId])->save();

                Notification::make()->title('Quarantined')->success()->send();
            });
    }

    public function markAccountedAction(): Action
    {
        return Action::make('markAccounted')
            ->label('Mark accounted')
            ->color('gray')
            ->action(function (array $arguments): void {
                $siteId = $this->resolvedSiteId();
                $epcId = (int) ($arguments['epc'] ?? 0);
                if ($siteId === null || $epcId < 1) {
                    return;
                }

                $epc = Epc::query()->find($epcId);
                if (! $epc instanceof Epc || ! $this->isCurrentSiteHit($epc, $siteId)) {
                    Notification::make()->title('Not a hit at this site')->danger()->send();

                    return;
                }

                $request = app(OpenRecallFlag::class)->matchingRecall($epc);

                if ($request === null) {
                    Notification::make()->title('No open recall')->danger()->send();

                    return;
                }

                $meta = is_array($request->response_metadata) ? $request->response_metadata : [];
                $key = 'site_'.$siteId;
                $existing = $meta['reconciled'][$key] ?? [];
                $existing[] = $epcId;
                $meta['reconciled'][$key] = array_values(array_unique(array_map('intval', $existing)));
                $request->forceFill(['response_metadata' => $meta])->save();

                Notification::make()->title('Marked accounted')->success()->send();
            });
    }

    private function isCurrentSiteHit(Epc $epc, int $siteId): bool
    {
        return app(OpenRecallHits::class)->epcsAtSite($siteId)
            ->contains(fn (Epc $hit): bool => (int) $hit->getKey() === (int) $epc->getKey());
    }

    private function isAccounted(Epc $epc, int $siteId): bool
    {
        $recall = app(OpenRecallFlag::class)->matchingRecall($epc);
        if ($recall === null) {
            return false;
        }

        $meta = is_array($recall->response_metadata) ? $recall->response_metadata : [];
        $ids = $meta['reconciled']['site_'.$siteId] ?? [];

        return in_array((int) $epc->getKey(), array_map('intval', $ids), true);
    }

    private function resolvedSiteId(): ?int
    {
        if ($this->siteId === null) {
            return null;
        }

        $user = $this->authUser();
        if ($user !== null && ! SiteAccess::canAccessSite($user, $this->siteId)) {
            return null;
        }

        return $this->siteId;
    }

    private function authUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function getDocumentation(): array|string
    {
        return 'compliance.recall-and-inspection';
    }
}

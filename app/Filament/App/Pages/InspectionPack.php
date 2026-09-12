<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Services\Dscsa\InspectionPackZipGenerator;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantFeatures;
use Filament\Actions\Action;
use App\Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Guava\FilamentKnowledgeBase\Contracts\HasKnowledgeBase;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class InspectionPack extends Page implements HasKnowledgeBase
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Inspection pack';

    protected static ?string $title = 'Inspection pack';

    protected static ?int $navigationSort = 11;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected string $view = 'filament.app.pages.inspection-pack';

    public ?int $siteId = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'inspection-pack';
    }

    public static function canAccess(): bool
    {
        return TenantFeatures::forTenant(tenant())->supportsComplianceReports()
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
        return 'ZIP of ATP licenses, open exceptions, quarantine holds, and the latest 3911 PDF. Compliance reports stay as they are.';
    }

    /**
     * @return array<int, string>
     */
    public function siteOptions(): array
    {
        return EligibleReceiveSites::options($this->authUser());
    }

    public function downloadPackAction(): Action
    {
        return Action::make('downloadPack')
            ->label('Download pack')
            ->color('primary')
            ->action(function (Action $action): StreamedResponse {
                $siteId = $this->resolvedSiteId();
                if ($siteId === null) {
                    Notification::make()->title('Select a site you can access.')->danger()->send();
                    $action->halt();
                }

                $pack = app(InspectionPackZipGenerator::class)->generate($siteId);

                return response()->streamDownload(
                    function () use ($pack): void {
                        echo $pack['binary'];
                    },
                    $pack['filename'],
                    ['Content-Type' => $pack['content_type']],
                );
            });
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

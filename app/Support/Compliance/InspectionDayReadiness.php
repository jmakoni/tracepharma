<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use App\Filament\App\Pages\AtpPartnerReadiness;
use App\Filament\App\Pages\ComplianceAlertCenter;
use App\Filament\App\Pages\ComplianceReports;
use App\Filament\App\Pages\InspectionPack;
use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Pages\SopLibrary;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Support\Auth\CurrentSite;
use App\Support\MasterData\AtpLicenseRelevance;
use App\Support\Receiving\EligibleReceiveSites;
use Throwable;

/**
 * FDA walk-in checklist — links existing evidence surfaces; no second ZIP engine.
 */
final class InspectionDayReadiness
{
    /**
     * @return list<array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}>
     */
    public function items(): array
    {
        $siteId = CurrentSite::id()
            ?? array_key_first(EligibleReceiveSites::options(auth()->user()));

        $openExceptions = ExceptionCase::query()->open()->count();
        $openHolds = QuarantineHold::query()->where('status', 'open')->count();
        $partnerAtpReachable = AtpPartnerReadiness::canAccess();
        $hasEvaluationJurisdictions = AtpLicenseRelevance::evaluationJurisdictionKeys() !== [];

        return [
            $this->item(
                id: 'receiving_state',
                title: 'ATP evaluation jurisdictions configured',
                description: 'Org facility states/countries (or preferred receiving state fallback) define which licenses are evaluated.',
                done: $hasEvaluationJurisdictions,
                href: $this->safeUrl(OrganizationSettings::class),
                actionLabel: 'Organization',
            ),
            $this->item(
                id: 'atp_snapshot',
                title: 'Partner ATP readiness reachable',
                description: 'Confirm partner sites have in-force WDD/ATP evidence for the walk-in narrative. Done means the ATP readiness page is accessible—not that every partner license was reviewed.',
                done: $partnerAtpReachable,
                href: $this->safeUrl(AtpPartnerReadiness::class),
                actionLabel: 'ATP readiness',
            ),
            $this->item(
                id: 'inspection_zip',
                title: 'Inspection pack ZIP available',
                description: 'Surface is accessible for the selected site (download ATP CSV, exceptions, holds, 3911). Marking done means you can open the pack—not that a ZIP was already exported today.',
                done: InspectionPack::canAccess() && $siteId !== null,
                href: $this->safeUrl(InspectionPack::class),
                actionLabel: 'Download ZIP',
            ),
            $this->item(
                id: 'exceptions_visible',
                title: 'Open exceptions & quarantine reachable',
                description: $openExceptions.' open exception(s), '.$openHolds.' open hold(s). Done means Alert Center is accessible for the walk-in—not that every case was reviewed.',
                done: ComplianceAlertCenter::canAccess(),
                href: $this->safeUrl(ComplianceAlertCenter::class),
                actionLabel: 'Alert Center',
            ),
            $this->item(
                id: 'compliance_reports',
                title: 'Compliance reports / TI exports reachable',
                description: 'Done means the Reports page is accessible so you can generate PDFs/CSVs when asked—not that a report was already run.',
                done: ComplianceReports::canAccess(),
                href: $this->safeUrl(ComplianceReports::class),
                actionLabel: 'Reports',
            ),
            $this->item(
                id: 'sop_starter',
                title: 'SOP starter pack reachable',
                description: 'Suspect product, recall sweep, 3911, and ATP review checklists. Done means the SOP library is accessible.',
                done: SopLibrary::canAccess(),
                href: $this->safeUrl(SopLibrary::class),
                actionLabel: 'SOP library',
            ),
            $this->item(
                id: 'alert_center',
                title: 'Alert Center reachable for walk-in triage',
                description: 'Integration, exception, ATP, queue, and expiry signals. Done means the page is accessible.',
                done: ComplianceAlertCenter::canAccess(),
                href: $this->safeUrl(ComplianceAlertCenter::class),
                actionLabel: 'Alert Center',
            ),
        ];
    }

    public function score(): int
    {
        $items = $this->items();
        if ($items === []) {
            return 0;
        }

        $done = count(array_filter($items, fn (array $item): bool => $item['done']));

        return (int) round(($done / count($items)) * 100);
    }

    /**
     * @return array{id: string, title: string, description: string, done: bool, href?: string, action_label?: string}
     */
    private function item(
        string $id,
        string $title,
        string $description,
        bool $done,
        ?string $href,
        string $actionLabel,
    ): array {
        $item = [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'done' => $done,
            'action_label' => $actionLabel,
        ];

        if ($href !== null) {
            $item['href'] = $href;
        }

        return $item;
    }

    /**
     * @param  class-string  $page
     */
    private function safeUrl(string $page): ?string
    {
        try {
            if (! $page::canAccess()) {
                return null;
            }

            return $page::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}

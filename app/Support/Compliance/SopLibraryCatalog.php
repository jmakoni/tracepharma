<?php

namespace App\Support\Compliance;

/**
 * Static DSCSA SOP cards. Not receive-edge-mode, not a second receiving stack.
 */
final class SopLibraryCatalog
{
    /**
     * @return list<array{slug: string, title: string, summary: string, steps: list<string>}>
     */
    public static function all(): array
    {
        return [
            [
                'slug' => 'suspect-product',
                'title' => 'Suspect product isolation',
                'summary' => 'Stop movement, quarantine, notify the supplier, and document the decision.',
                'steps' => [
                    'Do not dispense, ship, or restock the unit.',
                    'Quarantine the serials from Exceptions or the site recall page.',
                    'Email the supplier through Investigator SLA / the exception portal.',
                    'Record the outcome in the exception case. Do not overwrite the Exceptions list.',
                ],
            ],
            [
                'slug' => 'recall-sweep',
                'title' => 'Site recall sweep',
                'summary' => 'Reconcile open recalls against on-hand serials at this site.',
                'steps' => [
                    'Open Site recall and confirm the current site.',
                    'Quarantine every hit still on the shelf.',
                    'Mark accounted units that are already shipped or not present.',
                    'Leave Find/Recall and broadcast email on the existing tracing request.',
                ],
            ],
            [
                'slug' => 'fda-3911-24h',
                'title' => 'FDA 3911 within 24 hours',
                'summary' => 'File Form 3911 when you determine a product is illegitimate.',
                'steps' => [
                    'Confirm the product is illegitimate (not only quarantined).',
                    'Open the existing FDA 3911 resource and complete the letter.',
                    'Notify trading partners named on the form.',
                    'Keep the generated PDF with the inspection pack.',
                ],
            ],
            [
                'slug' => 'atp-license-review',
                'title' => 'ATP license review',
                'summary' => 'Confirm authorized trading partner licenses before shipping.',
                'steps' => [
                    'Review site ATP licenses on Sites. This listing is self-reported WDD evidence.',
                    'Do not ship to a location that fails ATP readiness.',
                    'Download the inspection pack for a license snapshot.',
                    'Leave Organization Settings and Sites as the system of record.',
                ],
            ],
        ];
    }
}

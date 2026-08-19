<?php

namespace App\Support\Marketing;

class MarketingBuyerVoice
{
    /**
     * @return list<array{
     *     label: string,
     *     quote: string,
     *     source_note: string,
     *     tracepharma_answer_route: string,
     *     tracepharma_answer_params?: array<string, string>,
     *     tracepharma_answer_label: string
     * }>
     */
    public static function themes(): array
    {
        return [
            [
                'label' => 'Missing or delayed EPCIS from suppliers',
                'quote' => 'Physical shipment arrives before EPCIS—receiving stays in spreadsheets until someone chases the manufacturer.',
                'source_note' => 'Synthesized from DSCSA operator forums, wholesaler LinkedIn discussions, and FDA stakeholder comments on data exchange timing—not attributed quotes.',
                'tracepharma_answer_route' => 'marketing.features.show',
                'tracepharma_answer_params' => ['feature' => 'receiving'],
                'tracepharma_answer_label' => 'Scan-first EPCIS receiving',
            ],
            [
                'label' => 'VRS and saleable returns complexity',
                'quote' => 'We verify at dispense, but returns lack audit trail when a serial fails mid-cycle.',
                'source_note' => 'Synthesized from pharmacy compliance webinar Q&A, NABP DSCSA education materials, and dispenser community threads—not attributed quotes.',
                'tracepharma_answer_route' => 'marketing.glossary.show',
                'tracepharma_answer_params' => ['term' => 'saleable-returns'],
                'tracepharma_answer_label' => 'Saleable returns workflows',
            ],
            [
                'label' => 'Manual spreadsheet reconciliation',
                'quote' => 'Compliance rebuilds serial lists in Excel weekly—the portal does not tie receiving to dock scans.',
                'source_note' => 'Synthesized from mid-market distributor evaluation calls, G2 traceability review themes, and DSCSA readiness assessments—not attributed quotes.',
                'tracepharma_answer_route' => 'marketing.compare.free-dscsa',
                'tracepharma_answer_label' => 'Why free DSCSA isn\'t free',
            ],
            [
                'label' => 'TraceLink Opus cost and network economics',
                'quote' => 'Opus works at enterprise scale, but per-partner fees add up when you are not a top-tier wholesaler.',
                'source_note' => 'Synthesized from L4 network TCO analyst briefings and mid-market IT leader interview themes—not attributed quotes.',
                'tracepharma_answer_route' => 'marketing.compare.tracelink',
                'tracepharma_answer_label' => 'TraceLink alternative',
            ],
            [
                'label' => 'Pharmacy PMS integration friction',
                'quote' => 'Verification must live inside Pioneer—not another browser tab technicians ignore.',
                'source_note' => 'Synthesized from PMS vendor DSCSA integration FAQs and dispenser IT survey themes—not attributed quotes.',
                'tracepharma_answer_route' => 'marketing.integrations.pms.index',
                'tracepharma_answer_label' => 'PMS integration hub',
            ],
            [
                'label' => 'Exception investigation without IT tickets',
                'quote' => 'Missing serial in EPCIS: warehouse calls IT, IT calls the vendor, we lose a day—no playbook.',
                'source_note' => 'Synthesized from wholesaler operations roundtables and GS1 healthcare traceability publications—not attributed quotes.',
                'tracepharma_answer_route' => 'marketing.blog.show',
                'tracepharma_answer_params' => ['slug' => 'epcis-exception-investigation-playbook'],
                'tracepharma_answer_label' => 'Exception investigation playbook',
            ],
        ];
    }
}

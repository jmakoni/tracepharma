<?php

namespace App\Actions\Marketing;

use App\Support\Marketing\DscsaProviderChecklist;
use App\Support\Marketing\MarketingPdf;

class GenerateProviderChecklistPdf
{
    public function execute(): string
    {
        return MarketingPdf::render('marketing.pdf.provider-checklist', [
            'sections' => DscsaProviderChecklist::sections(),
        ]);
    }
}

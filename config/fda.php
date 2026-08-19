<?php

return [
    'decrs_url' => env('FDA_DECRS_URL', 'https://www.accessdata.fda.gov/cder/drls_reg.zip'),
    'wdd_url' => env('FDA_WDD_URL', 'https://www.accessdata.fda.gov/cder/wdd_3pl_facilities_report.txt'),
    'match' => [
        'high_threshold' => (float) env('FDA_ORG_MATCH_HIGH', 92),
        'low_threshold' => (float) env('FDA_ORG_MATCH_LOW', 75),
    ],
];

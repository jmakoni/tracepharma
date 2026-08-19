<?php

use App\Http\Controllers\Marketing\CustomerOnboardingController;
use App\Http\Controllers\Marketing\DemoRequestController;
use App\Http\Controllers\Marketing\MarketingPageController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

$marketingDomains = array_values(array_unique(array_filter([
    config('tracepharma.marketing_domain'),
    config('tracepharma.central_domain'),
    app()->environment(['local', 'testing']) ? 'localhost' : null,
    app()->environment(['local', 'testing']) ? '127.0.0.1' : null,
])));

$namedDomain = (string) (config('tracepharma.marketing_domain') ?: config('tracepharma.central_domain') ?: 'localhost');

if ($namedDomain === '' || ! in_array($namedDomain, $marketingDomains, true)) {
    $namedDomain = $marketingDomains[0] ?? $namedDomain;
}

$registerMarketingRoutes = function (bool $named): void {
    $n = function (RoutingRoute $route, string $name) use ($named): void {
        if ($named) {
            $route->name($name);
        }
    };

    $n(Route::get('/', [MarketingPageController::class, 'home']), 'marketing.home');
    $n(Route::get('/features', [MarketingPageController::class, 'features']), 'marketing.features');
    $n(Route::get('/features/{feature}', [MarketingPageController::class, 'feature'])
        ->where('feature', 'verification|receiving|exceptions|compliance|integrations|serialization'), 'marketing.features.show');
    $n(Route::get('/solutions/manufacturers', [MarketingPageController::class, 'manufacturers']), 'marketing.solutions.manufacturers');
    $n(Route::get('/solutions/wholesalers', [MarketingPageController::class, 'wholesalers']), 'marketing.solutions.wholesalers');
    $n(Route::get('/solutions/pharmacies', [MarketingPageController::class, 'pharmacies']), 'marketing.solutions.pharmacies');
    $n(Route::get('/solutions/3pl', [MarketingPageController::class, 'threePl']), 'marketing.solutions.3pl');
    $n(Route::get('/solutions/buying-groups', [MarketingPageController::class, 'buyingGroups']), 'marketing.solutions.buying-groups');
    $n(Route::get('/solutions/dental-medical', [MarketingPageController::class, 'dentalMedical']), 'marketing.solutions.dental-medical');
    $n(Route::get('/solutions/prepackagers', [MarketingPageController::class, 'prepackagers']), 'marketing.solutions.prepackagers');
    $n(Route::get('/pricing', [MarketingPageController::class, 'pricing']), 'marketing.pricing');
    $n(Route::get('/sitemap.xml', [MarketingPageController::class, 'sitemap']), 'marketing.sitemap');
    $n(Route::get('/guides/epcis-vs-asn', [MarketingPageController::class, 'epcisVsAsn']), 'marketing.guides.epcis-vs-asn');
    $n(Route::get('/resources', [MarketingPageController::class, 'resourcesIndex']), 'marketing.resources.index');
    $n(Route::get('/blog/{slug}', [MarketingPageController::class, 'blogPost'])
        ->where('slug', 'scan-first-receiving-wholesalers|dscsa-saleable-returns|choosing-l4-dscsa-provider|epcis-exception-investigation-playbook'), 'marketing.blog.show');
    $n(Route::get('/customers/{slug}', [MarketingPageController::class, 'caseStudy'])
        ->where('slug', 'regional-wholesaler-receive-to-ship|independent-pharmacy-epcis-vrs|manufacturer-outbound-ack-health'), 'marketing.customers.show');
    $n(Route::get('/glossary', [MarketingPageController::class, 'glossary']), 'marketing.glossary');
    $n(Route::get('/glossary/{term}', [MarketingPageController::class, 'glossaryTerm'])
        ->where('term', 'epcis|vrs|dscsa-3t|gtin-sgtin|asn|gln|sscc|fda-3911|atp|saleable-returns|l4|epcis-2-0'), 'marketing.glossary.show');
    $n(Route::get('/about', [MarketingPageController::class, 'about']), 'marketing.about');
    $n(Route::get('/legal', [MarketingPageController::class, 'legal']), 'marketing.legal');
    $n(Route::get('/tos', [MarketingPageController::class, 'tos']), 'marketing.tos');
    $n(Route::get('/privacy', [MarketingPageController::class, 'privacy']), 'marketing.privacy');
    $n(Route::get('/contact', [MarketingPageController::class, 'contact']), 'marketing.contact');
    $n(Route::get('/integrations', [MarketingPageController::class, 'integrationsIndex']), 'marketing.integrations.index');
    $n(Route::get('/integrations/pms', [MarketingPageController::class, 'pmsIntegrationsIndex']), 'marketing.integrations.pms.index');
    $n(Route::get('/integrations/pms/{vendor}', [MarketingPageController::class, 'pmsIntegration'])
        ->where('vendor', 'pioneerrx|bestrx|primerx|liberty|qs1|enterpriserx|scriptpro'), 'marketing.integrations.pms.show');
    $n(Route::get('/integrations/wms', [MarketingPageController::class, 'wmsIntegrationsIndex']), 'marketing.integrations.wms.index');
    $n(Route::get('/integrations/wms/{vendor}', [MarketingPageController::class, 'wmsIntegration'])
        ->where('vendor', 'manhattan|korber'), 'marketing.integrations.wms.show');
    $n(Route::get('/integrations/wholesale', [MarketingPageController::class, 'wholesaleIntegrationsIndex']), 'marketing.integrations.wholesale.index');
    $n(Route::get('/integrations/wholesale/{preset}', [MarketingPageController::class, 'wholesaleIntegration'])
        ->where('preset', 'cardinal|mckesson-as2|mckesson-https|cencora|morris-dickson'), 'marketing.integrations.wholesale.show');
    $n(Route::get('/integrations/edi-as2', [MarketingPageController::class, 'ediAs2Integration']), 'marketing.integrations.edi-as2');
    $n(Route::get('/integrations/erp', [MarketingPageController::class, 'erpIntegrationsIndex']), 'marketing.integrations.erp.index');
    $n(Route::get('/integrations/erp/{vendor}', [MarketingPageController::class, 'erpIntegration'])
        ->where('vendor', 'oracle|netsuite|dynamics365'), 'marketing.integrations.erp.show');
    $n(Route::get('/integrations/nabp-pulse', [MarketingPageController::class, 'nabpPulseIntegration']), 'marketing.integrations.nabp-pulse');
    $n(Route::get('/integrations/{integration}', [MarketingPageController::class, 'integration'])
        ->where('integration', 'tracelink|lspedia|infinitrak|advasur|gateway-checker|unitrace|axway|rfxcel|tracktracerx|sap|optel'), 'marketing.integrations.show');
    $n(Route::get('/compare', [MarketingPageController::class, 'compareIndex']), 'marketing.compare.index');
    $n(Route::get('/compare/free-dscsa', [MarketingPageController::class, 'compareFreeDscsa']), 'marketing.compare.free-dscsa');
    $n(Route::get('/compare/lspedia-alternative', [MarketingPageController::class, 'compareLspedia']), 'marketing.compare.lspedia');
    $n(Route::get('/compare/infinitrak-alternative', [MarketingPageController::class, 'compareInfinitrak']), 'marketing.compare.infinitrak');
    $n(Route::get('/compare/tracelink-alternative', [MarketingPageController::class, 'compareTraceLink']), 'marketing.compare.tracelink');
    $n(Route::get('/compare/advasur-alternative', [MarketingPageController::class, 'compareAdvasur']), 'marketing.compare.advasur');
    $n(Route::get('/compare/gateway-checker-alternative', [MarketingPageController::class, 'compareGatewayChecker']), 'marketing.compare.gateway-checker');
    $n(Route::get('/compare/tracktracerx-alternative', [MarketingPageController::class, 'compareTrackTraceRx']), 'marketing.compare.tracktracerx');
    $n(Route::get('/compare/optel-alternative', [MarketingPageController::class, 'compareOptel']), 'marketing.compare.optel');
    $n(Route::get('/compare/checklist', [MarketingPageController::class, 'compareChecklist']), 'marketing.compare.checklist');
    $n(Route::get('/compare/checklist.pdf', [MarketingPageController::class, 'downloadProviderChecklist']), 'marketing.compare.checklist.pdf');
    $n(Route::get('/demo', [MarketingPageController::class, 'demo']), 'marketing.demo');
    $n(Route::post('/demo', [DemoRequestController::class, 'store'])->middleware('throttle:marketing-leads'), 'marketing.demo.store');
    $n(Route::get('/get-started', [CustomerOnboardingController::class, 'create']), 'marketing.get-started');
    $n(Route::post('/get-started', [CustomerOnboardingController::class, 'store'])->middleware('throttle:marketing-leads'), 'marketing.get-started.store');
};

Route::domain($namedDomain)->middleware('web')->group(fn () => $registerMarketingRoutes(true));

foreach ($marketingDomains as $domain) {
    if ($domain === $namedDomain) {
        continue;
    }

    Route::domain($domain)->middleware('web')->group(fn () => $registerMarketingRoutes(false));
}

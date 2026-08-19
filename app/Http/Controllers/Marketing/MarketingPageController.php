<?php

namespace App\Http\Controllers\Marketing;

use App\Actions\Marketing\GenerateProviderChecklistPdf;
use App\Http\Controllers\Controller;
use App\Support\Marketing\DscsaProviderChecklist;
use App\Support\Marketing\GlossaryTerms;
use App\Support\Marketing\MarketingIntegrationPages;
use App\Support\Marketing\MarketingPlatformIntegrations;
use App\Support\Marketing\MarketingResources;
use App\Support\Marketing\MarketingSitemap;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MarketingPageController extends Controller
{
    private const FEATURE_PAGES = [
        'verification',
        'receiving',
        'exceptions',
        'compliance',
        'integrations',
        'serialization',
    ];

    public function home(): View
    {
        return view('marketing.home');
    }

    public function features(): View
    {
        return view('marketing.features');
    }

    public function feature(string $feature): View
    {
        abort_unless(in_array($feature, self::FEATURE_PAGES, true), 404);

        return view('marketing.features.'.$feature);
    }

    public function sitemap(): Response
    {
        $xml = view('marketing.sitemap', [
            'entries' => MarketingSitemap::entries(),
        ])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }

    public function pricing(): View
    {
        return view('marketing.pricing');
    }

    public function compareIndex(): View
    {
        return view('marketing.compare.index');
    }

    public function compareFreeDscsa(): View
    {
        return view('marketing.compare.free-dscsa');
    }

    public function compareLspedia(): View
    {
        return view('marketing.compare.lspedia-alternative');
    }

    public function compareInfinitrak(): View
    {
        return view('marketing.compare.infinitrak-alternative');
    }

    public function compareTraceLink(): View
    {
        return view('marketing.compare.tracelink-alternative');
    }

    public function compareAdvasur(): View
    {
        return view('marketing.compare.advasur-alternative');
    }

    public function compareGatewayChecker(): View
    {
        return view('marketing.compare.gateway-checker-alternative');
    }

    public function compareTrackTraceRx(): View
    {
        return view('marketing.compare.tracktracerx-alternative');
    }

    public function compareOptel(): View
    {
        return view('marketing.compare.optel-alternative');
    }

    public function integrationsIndex(): View
    {
        return view('marketing.integrations.index');
    }

    public function pmsIntegrationsIndex(): View
    {
        return view('marketing.integrations.pms.index');
    }

    public function pmsIntegration(string $vendor): View
    {
        abort_unless(in_array($vendor, MarketingPlatformIntegrations::pmsSlugs(), true), 404);

        return view('marketing.integrations.show', [
            'integration' => MarketingPlatformIntegrations::getPms($vendor),
            'breadcrumbParent' => ['label' => 'Pharmacy PMS', 'route' => 'marketing.integrations.pms.index'],
        ]);
    }

    public function wmsIntegrationsIndex(): View
    {
        return view('marketing.integrations.wms.index');
    }

    public function wmsIntegration(string $vendor): View
    {
        abort_unless(in_array($vendor, MarketingPlatformIntegrations::wmsSlugs(), true), 404);

        return view('marketing.integrations.show', [
            'integration' => MarketingPlatformIntegrations::getWms($vendor),
            'breadcrumbParent' => ['label' => 'WMS', 'route' => 'marketing.integrations.wms.index'],
        ]);
    }

    public function wholesaleIntegrationsIndex(): View
    {
        return view('marketing.integrations.wholesale.index');
    }

    public function wholesaleIntegration(string $preset): View
    {
        abort_unless(in_array($preset, MarketingPlatformIntegrations::wholesaleSlugs(), true), 404);

        return view('marketing.integrations.show', [
            'integration' => MarketingPlatformIntegrations::getWholesale($preset),
            'breadcrumbParent' => ['label' => 'Wholesalers', 'route' => 'marketing.integrations.wholesale.index'],
        ]);
    }

    public function ediAs2Integration(): View
    {
        return view('marketing.integrations.edi-as2');
    }

    public function erpIntegrationsIndex(): View
    {
        return view('marketing.integrations.erp.index');
    }

    public function erpIntegration(string $vendor): View
    {
        abort_unless(in_array($vendor, MarketingPlatformIntegrations::erpSlugs(), true), 404);

        return view('marketing.integrations.show', [
            'integration' => MarketingPlatformIntegrations::getErp($vendor),
            'breadcrumbParent' => ['label' => 'ERP', 'route' => 'marketing.integrations.erp.index'],
        ]);
    }

    public function nabpPulseIntegration(): View
    {
        return view('marketing.integrations.nabp-pulse');
    }

    public function integration(string $integration): View
    {
        abort_unless(in_array($integration, MarketingIntegrationPages::slugs(), true), 404);

        return view('marketing.integrations.show', [
            'integration' => MarketingIntegrationPages::get($integration),
        ]);
    }

    public function compareChecklist(): View
    {
        return view('marketing.compare.checklist', [
            'sections' => DscsaProviderChecklist::sections(),
        ]);
    }

    public function downloadProviderChecklist(GenerateProviderChecklistPdf $generator): Response
    {
        return response($generator->execute(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tracepharma-dscsa-provider-checklist.pdf"',
        ]);
    }

    public function epcisVsAsn(): View
    {
        return view('marketing.guides.epcis-vs-asn');
    }

    public function glossary(): View
    {
        return view('marketing.glossary.index');
    }

    public function glossaryTerm(string $term): View
    {
        abort_unless(in_array($term, GlossaryTerms::slugs(), true), 404);

        return view('marketing.glossary.show', [
            'term' => GlossaryTerms::get($term),
        ]);
    }

    public function resourcesIndex(): View
    {
        return view('marketing.resources.index');
    }

    public function blogPost(string $slug): View
    {
        abort_unless(in_array($slug, MarketingResources::blogSlugs(), true), 404);

        return view('marketing.blog.show', [
            'post' => MarketingResources::getBlogPost($slug),
        ]);
    }

    public function caseStudy(string $slug): View
    {
        abort_unless(in_array($slug, MarketingResources::caseStudySlugs(), true), 404);

        return view('marketing.customers.show', [
            'study' => MarketingResources::getCaseStudy($slug),
        ]);
    }

    public function demo(): View
    {
        return view('marketing.demo');
    }

    public function about(): View
    {
        return view('marketing.about');
    }

    public function legal(): View
    {
        return view('marketing.legal');
    }

    public function tos(): View
    {
        return view('marketing.tos');
    }

    public function privacy(): View
    {
        return view('marketing.privacy');
    }

    public function contact(): View
    {
        return view('marketing.contact');
    }

    public function manufacturers(): View
    {
        return view('marketing.solutions.manufacturers');
    }

    public function wholesalers(): View
    {
        return view('marketing.solutions.wholesalers');
    }

    public function pharmacies(): View
    {
        return view('marketing.solutions.pharmacies');
    }

    public function threePl(): View
    {
        return view('marketing.solutions.3pl');
    }

    public function buyingGroups(): View
    {
        return view('marketing.solutions.buying-groups');
    }

    public function dentalMedical(): View
    {
        return view('marketing.solutions.dental-medical');
    }

    public function prepackagers(): View
    {
        return view('marketing.solutions.prepackagers');
    }
}

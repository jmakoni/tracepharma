<?php

namespace App\Support\Marketing;

class MarketingSitemap
{
    /**
     * @return list<array{loc: string, changefreq: string, priority: string}>
     */
    public static function entries(): array
    {
        $routes = [
            ['name' => 'marketing.home', 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['name' => 'marketing.pricing', 'changefreq' => 'monthly', 'priority' => '0.9'],
            ['name' => 'marketing.features', 'changefreq' => 'monthly', 'priority' => '0.9'],
            ['name' => 'marketing.features.show', 'params' => ['feature' => 'receiving'], 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.features.show', 'params' => ['feature' => 'serialization'], 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.features.show', 'params' => ['feature' => 'integrations'], 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.features.show', 'params' => ['feature' => 'exceptions'], 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.features.show', 'params' => ['feature' => 'verification'], 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.features.show', 'params' => ['feature' => 'compliance'], 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.solutions.manufacturers', 'changefreq' => 'monthly', 'priority' => '0.9'],
            ['name' => 'marketing.solutions.wholesalers', 'changefreq' => 'monthly', 'priority' => '0.9'],
            ['name' => 'marketing.solutions.3pl', 'changefreq' => 'monthly', 'priority' => '0.9'],
            ['name' => 'marketing.solutions.pharmacies', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.solutions.prepackagers', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.solutions.buying-groups', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.solutions.dental-medical', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.guides.epcis-vs-asn', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.resources.index', 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['name' => 'marketing.glossary', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.index', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.integrations.pms.index', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.integrations.wms.index', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.integrations.wholesale.index', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.integrations.edi-as2', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.erp.index', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.nabp-pulse', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'tracelink'], 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'lspedia'], 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'infinitrak'], 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'advasur'], 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'gateway-checker'], 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'unitrace'], 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'axway'], 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.compare.index', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.compare.lspedia', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.compare.infinitrak', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.compare.tracelink', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['name' => 'marketing.compare.advasur', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.compare.gateway-checker', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.compare.tracktracerx', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.compare.optel', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'rfxcel'], 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'tracktracerx'], 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'sap'], 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['name' => 'marketing.integrations.show', 'params' => ['integration' => 'optel'], 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['name' => 'marketing.compare.free-dscsa', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.compare.checklist', 'changefreq' => 'monthly', 'priority' => '0.7'],
            ['name' => 'marketing.demo', 'changefreq' => 'monthly', 'priority' => '0.9'],
            ['name' => 'marketing.get-started', 'changefreq' => 'monthly', 'priority' => '0.9'],
            ['name' => 'marketing.about', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['name' => 'marketing.tos', 'changefreq' => 'yearly', 'priority' => '0.4'],
            ['name' => 'marketing.privacy', 'changefreq' => 'yearly', 'priority' => '0.4'],
            ['name' => 'marketing.legal', 'changefreq' => 'yearly', 'priority' => '0.4'],
            ['name' => 'marketing.contact', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ];

        foreach (MarketingPlatformIntegrations::pmsSlugs() as $slug) {
            $routes[] = [
                'name' => 'marketing.integrations.pms.show',
                'params' => ['vendor' => $slug],
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        foreach (MarketingPlatformIntegrations::wmsSlugs() as $slug) {
            $routes[] = [
                'name' => 'marketing.integrations.wms.show',
                'params' => ['vendor' => $slug],
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        foreach (MarketingPlatformIntegrations::wholesaleSlugs() as $slug) {
            $routes[] = [
                'name' => 'marketing.integrations.wholesale.show',
                'params' => ['preset' => $slug],
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        foreach (MarketingPlatformIntegrations::erpSlugs() as $slug) {
            $routes[] = [
                'name' => 'marketing.integrations.erp.show',
                'params' => ['vendor' => $slug],
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        foreach (GlossaryTerms::slugs() as $slug) {
            $routes[] = [
                'name' => 'marketing.glossary.show',
                'params' => ['term' => $slug],
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        foreach (MarketingResources::blogSlugs() as $slug) {
            $routes[] = [
                'name' => 'marketing.blog.show',
                'params' => ['slug' => $slug],
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        foreach (MarketingResources::caseStudySlugs() as $slug) {
            $routes[] = [
                'name' => 'marketing.customers.show',
                'params' => ['slug' => $slug],
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        return array_map(static function (array $entry): array {
            $params = $entry['params'] ?? [];

            return [
                'loc' => route($entry['name'], $params, absolute: true),
                'changefreq' => $entry['changefreq'],
                'priority' => $entry['priority'],
            ];
        }, $routes);
    }
}

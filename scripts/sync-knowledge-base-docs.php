<?php

declare(strict_types=1);

/**
 * Sync Guava Filament Knowledge Base trees from source markdown.
 *
 * Sources:
 * - docs/workflows/*.md          → docs/knowledge-base/en/workflows/ (+ cbv/findings at locale root)
 * - docs/kb-source/app/{group}/  → docs/knowledge-base/en/{group}/
 * - docs/kb-source/admin/{group}/→ docs/admin-knowledge-base/en/{group}/
 */
$tenantDst = __DIR__.'/../docs/knowledge-base/en';
$adminDst = __DIR__.'/../docs/admin-knowledge-base/en';
$workflowsSrc = __DIR__.'/../docs/workflows';
$appSrcRoot = __DIR__.'/../docs/kb-source/app';
$adminSrcRoot = __DIR__.'/../docs/kb-source/admin';

$workflowTitles = [
    'cbv-biz-steps' => 'CBV biz steps',
    'findings' => 'Capture findings',
    'shell-and-site' => 'Shell and site',
    'outbound-shipping' => 'Outbound shipping',
    'saleable-return' => 'Saleable return',
    'repack-transform' => 'Repack transform',
    'verify-product' => 'Verify product',
    'asset-tracking' => 'Asset tracking',
    'receiving-issues' => 'Receiving issues',
    'pharmacy-outbound' => 'Pharmacy outbound',
    'break-pack' => 'Break and pack',
];

$tenantGroups = [
    'workflows' => [
        'title' => 'Operator workflows',
        'icon' => 'heroicon-o-squares-2x2',
        'order' => 20,
        'nav' => 'Operations',
        'blurb' => 'Floor and desk procedures for receiving, shipping, disposition, and verification.',
    ],
    'compliance' => [
        'title' => 'Compliance',
        'icon' => 'heroicon-o-shield-check',
        'order' => 30,
        'nav' => 'Compliance',
        'blurb' => 'Quarantine, recalls, inspection readiness, ATP, reports, and leadership packs.',
    ],
    'integrations' => [
        'title' => 'Integrations',
        'icon' => 'heroicon-o-puzzle-piece',
        'order' => 40,
        'nav' => 'Integrations',
        'blurb' => 'Connection health, EPCIS subscriptions, API tokens, and partner packs.',
    ],
    'master-data' => [
        'title' => 'Master data',
        'icon' => 'heroicon-o-circle-stack',
        'order' => 50,
        'nav' => 'Master Data',
        'blurb' => 'Products, trading partners, sites, devices, and customer portal links.',
    ],
    'settings' => [
        'title' => 'Settings',
        'icon' => 'heroicon-o-cog-6-tooth',
        'order' => 60,
        'nav' => 'Settings',
        'blurb' => 'Organization settings, users, labeling, and onboarding.',
    ],
    'exceptions' => [
        'title' => 'Exceptions and EPCIS',
        'icon' => 'heroicon-o-exclamation-triangle',
        'order' => 25,
        'nav' => 'Receiving',
        'blurb' => 'Exception cases, investigator SLA, inbound EPCIS, and verification history.',
    ],
    'operations' => [
        'title' => 'Operations extras',
        'icon' => 'heroicon-o-queue-list',
        'order' => 22,
        'nav' => 'Operations',
        'blurb' => 'On-hand lists, EPCIS jobs, outbound EPCIS, activity log, and buying group.',
    ],
];

$adminGroups = [
    'tenants' => [
        'title' => 'Tenants',
        'icon' => 'heroicon-o-building-office-2',
        'order' => 20,
        'nav' => 'Tenants',
        'blurb' => 'Tenant provisioning, customer onboarding, and demo requests.',
    ],
    'registry' => [
        'title' => 'FDA registry',
        'icon' => 'heroicon-o-building-library',
        'order' => 30,
        'nav' => 'Registry',
        'blurb' => 'FDA organizations, establishments, products, WDD, and match review.',
    ],
    'operations' => [
        'title' => 'Platform operations',
        'icon' => 'heroicon-o-wrench-screwdriver',
        'order' => 40,
        'nav' => 'Operations',
        'blurb' => 'FDA imports, WDD 3PL staging, and EPCIS hub settings.',
    ],
    'platform' => [
        'title' => 'Platform',
        'icon' => 'heroicon-o-server-stack',
        'order' => 50,
        'nav' => 'Settings',
        'blurb' => 'Analytics, mail templates, admins, and activity log.',
    ],
];

function kbTitleFromSlug(string $slug, array $overrides = []): string
{
    $defaults = [
        'fda-organizations' => 'FDA organizations',
        'fda-establishments' => 'FDA establishments',
        'fda-products' => 'FDA products',
        'fda-wdd' => 'FDA WDD',
        'fda-imports' => 'FDA imports',
        'wdd-3pl-staging' => 'WDD 3PL staging',
        'epcis-hub-settings' => 'EPCIS hub settings',
        'l3-forward-log' => 'L3 forward log',
        'pms-and-wholesaler-packs' => 'PMS and wholesaler packs',
        'leadership-dscsa-pack' => 'Leadership DSCSA pack',
        'tracing-and-fda3911' => 'Tracing and FDA 3911',
        'on-hand-and-unpacked' => 'On-hand and unpacked',
        'api-tokens' => 'API tokens',
        'epcis-subscriptions' => 'EPCIS subscriptions',
        'epcis-jobs' => 'EPCIS jobs',
        'outbound-epcis' => 'Outbound EPCIS',
        'inbound-epcis' => 'Inbound EPCIS',
        'investigator-sla' => 'Investigator SLA',
        'atp-readiness' => 'ATP readiness',
    ];

    if (isset($overrides[$slug])) {
        return $overrides[$slug];
    }

    if (isset($defaults[$slug])) {
        return $defaults[$slug];
    }

    return ucwords(str_replace('-', ' ', $slug));
}

function kbRewriteBody(string $content, string $group, array $siblingGroups): string
{
    $body = preg_replace('/^# .+\n+/', '', $content, 1) ?? $content;

    // Cross-group roots used by workflows.
    $body = str_replace(
        ['](cbv-biz-steps.md)', '](findings.md)'],
        ['](../cbv-biz-steps)', '](../findings)'],
        $body,
    );

    // Explicit ../group/slug.md → Guava ../group/slug
    $body = preg_replace(
        '/\]\(\.\.\/([a-z0-9\-]+)\/([a-z0-9\-]+)\.md\)/',
        '](../$1/$2)',
        $body,
    ) ?? $body;

    // Same-directory sibling .md → ../{group}/slug (Guava child link style)
    $body = preg_replace(
        '/\]\(([a-z0-9\-]+)\.md\)/',
        '](../'.$group.'/$1)',
        $body,
    ) ?? $body;

    return $body;
}

function kbWriteGroupParent(string $dst, string $group, array $meta): void
{
    @mkdir($dst.'/'.$group, 0777, true);
    $path = $dst.'/'.$group.'.md';
    $fm = <<<MD
---
title: {$meta['title']}
icon: {$meta['icon']}
order: {$meta['order']}
group: {$meta['nav']}
---
# {$meta['title']}

{$meta['blurb']}

Open a child page from the sidebar for procedures and checklists.
MD;
    file_put_contents($path, $fm);
    echo "Wrote {$path}\n";
}

function kbSyncGroupFromDir(
    string $srcDir,
    string $dst,
    string $group,
    array $meta,
    array $titleOverrides = [],
    int $defaultOrder = 20,
): int {
    if (! is_dir($srcDir)) {
        return 0;
    }

    kbWriteGroupParent($dst, $group, $meta);
    $count = 0;
    $order = $defaultOrder;

    foreach (glob($srcDir.'/*.md') ?: [] as $file) {
        $slug = pathinfo($file, PATHINFO_FILENAME);
        if ($slug === 'README') {
            continue;
        }

        $title = kbTitleFromSlug($slug, $titleOverrides);
        $content = file_get_contents($file) ?: '';
        $body = kbRewriteBody($content, $group, []);
        $out = $dst.'/'.$group.'/'.$slug.'.md';
        $fm = "---\ntitle: {$title}\nparent: {$group}\norder: {$order}\ngroup: {$meta['nav']}\n---\n\n";
        file_put_contents($out, $fm.'# '.$title."\n\n".ltrim($body));
        echo "Wrote {$out}\n";
        $count++;
        $order += 5;
    }

    return $count;
}

// --- Tenant intro + workflows from docs/workflows ---
@mkdir($tenantDst.'/workflows', 0777, true);

file_put_contents($tenantDst.'/intro.md', <<<'MD'
---
title: Welcome
icon: heroicon-o-home
order: 1
---
# TracePharma operator help

In-app knowledge base for the tenant App panel.

- Use **Help** on pages linked to an article.
- Full panel: user menu → **Operator help**, or `/help`.
- Groups cover workflows, exceptions, compliance, integrations, master data, settings, and ops extras.

Marketing / public guides stay on the marketing site (not this panel).
MD);

kbWriteGroupParent($tenantDst, 'workflows', $tenantGroups['workflows']);

foreach (glob($workflowsSrc.'/*.md') ?: [] as $file) {
    $base = basename($file);
    if ($base === 'README.md') {
        continue;
    }

    $slug = pathinfo($base, PATHINFO_FILENAME);
    $title = kbTitleFromSlug($slug, $workflowTitles);
    $order = match ($slug) {
        'cbv-biz-steps' => 10,
        'findings' => 90,
        'shell-and-site' => 1,
        default => 20,
    };

    $content = file_get_contents($file) ?: '';
    $body = kbRewriteBody($content, 'workflows', []);

    if (in_array($slug, ['cbv-biz-steps', 'findings'], true)) {
        $out = $tenantDst.'/'.$slug.'.md';
        $fm = "---\ntitle: {$title}\norder: {$order}\n---\n\n";
        file_put_contents($out, $fm.'# '.$title."\n\n".ltrim($body));
    } else {
        $out = $tenantDst.'/workflows/'.$slug.'.md';
        $fm = "---\ntitle: {$title}\nparent: workflows\norder: {$order}\ngroup: Operations\n---\n\n";
        file_put_contents($out, $fm.'# '.$title."\n\n".ltrim($body));
    }

    echo "Wrote {$out}\n";
}

// --- Tenant clusters from docs/kb-source/app ---
$tenantArticleCount = 0;
foreach ($tenantGroups as $group => $meta) {
    if ($group === 'workflows') {
        continue;
    }
    $tenantArticleCount += kbSyncGroupFromDir(
        $appSrcRoot.'/'.$group,
        $tenantDst,
        $group,
        $meta,
    );
}

// --- Admin intro + clusters ---
@mkdir($adminDst, 0777, true);

file_put_contents($adminDst.'/intro.md', <<<'MD'
---
title: Admin help
icon: heroicon-o-home
order: 1
---
# TracePharma admin help

Internal documentation for the Admin panel (`admin2` host).

Tenant floor SOPs live on each customer domain under `/help`, not here.

## Groups

- Tenants — provisioning and onboarding
- FDA registry — organizations, establishments, products, WDD
- Platform operations — imports, 3PL staging, EPCIS hub
- Platform — analytics, mail, admins, activity log
MD);

file_put_contents($adminDst.'/demo-and-support.md', <<<'MD'
---
title: Demo and support
icon: heroicon-o-lifebuoy
order: 10
---
# Demo and support

## Demo hosts

See the repo README for current demo URLs and passwords.

## Tenant operator help

After logging into a tenant domain, open **Operator help** from the user menu or visit `/help`.

## Marketing

Public marketing/guides remain on the marketing Blade site — not in this Filament knowledge base.
MD);

$adminArticleCount = 0;
foreach ($adminGroups as $group => $meta) {
    $adminArticleCount += kbSyncGroupFromDir(
        $adminSrcRoot.'/'.$group,
        $adminDst,
        $group,
        $meta,
    );
}

$mediaLink = __DIR__.'/../public/docs-media/workflows';
$mediaTarget = '../../docs/workflows/media';
@mkdir(dirname($mediaLink), 0777, true);
if (! is_link($mediaLink) && ! file_exists($mediaLink)) {
    symlink($mediaTarget, $mediaLink);
    echo "Linked public/docs-media/workflows → {$mediaTarget}\n";
}

echo "Tenant cluster articles: {$tenantArticleCount}\n";
echo 'Workflow pages: '.count(glob($tenantDst.'/workflows/*.md') ?: [])."\n";
echo "Admin cluster articles: {$adminArticleCount}\n";

<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Actions\Portal\EnsurePortalOrganization;
use App\Actions\Shipping\CompleteOutboundShippingSession;
use App\Actions\Shipping\ConfirmOutboundShippingScan;
use App\Actions\Shipping\OpenOutboundShippingSession;
use App\Actions\Shipping\UpdateOutboundShippingParty;
use App\Actions\Shipping\UpdateOutboundShippingReferences;
use App\Enums\FacilityType;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Models\AtpLicense;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\OutboundConnection;
use App\Models\PortalPublication;
use App\Models\PortalUser;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Models\User;
use App\Support\Gs1\Sgln;
use App\Support\Shipping\ShippableEpcsAtSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';
const SHIP_FROM_SITE_ID = 6693;
const PARTNER_NAME = 'Joel Test Dispenser';
const PORTAL_EMAIL = 'joel.makoni@gmail.com';
const PALLET_URI = 'urn:epc:id:sscc:030116.01001228888';
const CASE_URI = 'urn:epc:id:sgtin:030116.5200116.10000082888801';
const BOTTLE1_URI = 'urn:epc:id:sgtin:030116.0200116.10000082888802';
const BOTTLE2_URI = 'urn:epc:id:sgtin:030116.0200116.10000082888803';
const HIERARCHY_DOC_FILENAME = 'demo-portal-hierarchy-seed.xml';

function gs1CheckDigit(string $bodyWithoutCheck): string
{
    $sum = 0;
    foreach (str_split(strrev($bodyWithoutCheck)) as $index => $digit) {
        $sum += ((int) $digit) * ($index % 2 === 0 ? 3 : 1);
    }

    return (string) ((10 - ($sum % 10)) % 10);
}

function uniqueGln(string $companyPrefix): string
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $body12 = $companyPrefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $gln = $body12.gs1CheckDigit($body12);
        if (! TradingPartner::query()->where('gln', $gln)->exists()
            && ! Site::query()->where('gln', $gln)->exists()) {
            return $gln;
        }
    }

    throw new RuntimeException('Unable to allocate a unique GLN.');
}

function attachEventEpc(int $eventId, int $epcId, string $role): void
{
    DB::table('event_epcs')->insertOrIgnore([
        'event_id' => $eventId,
        'epc_id' => $epcId,
        'role' => $role,
    ]);
}

function createObjectEvent(
    EpcisDocument $document,
    Epc $epc,
    string $bizStep,
    ?string $readGln = null,
    ?string $bizGln = null,
): EpcisEvent {
    $event = EpcisEvent::query()->create([
        'document_id' => $document->getKey(),
        'event_id' => 'urn:uuid:'.(string) Str::uuid(),
        'event_type' => 'ObjectEvent',
        'event_time' => now()->subHours(2),
        'record_time' => now()->subHours(2),
        'event_timezone_offset' => '+00:00',
        'action' => 'ADD',
        'biz_step' => $bizStep,
        'disposition' => 'urn:epcglobal:cbv:disp:active',
        'read_point_gln' => $readGln,
        'biz_location_gln' => $bizGln ?? $readGln,
    ]);
    attachEventEpc((int) $event->getKey(), (int) $epc->getKey(), 'epcList');

    return $event;
}

function createAggregationEvent(
    EpcisDocument $document,
    Epc $parent,
    array $children,
    ?string $readGln = null,
): EpcisEvent {
    $event = EpcisEvent::query()->create([
        'document_id' => $document->getKey(),
        'event_id' => 'urn:uuid:'.(string) Str::uuid(),
        'event_type' => 'AggregationEvent',
        'event_time' => now()->subHour(),
        'record_time' => now()->subHour(),
        'event_timezone_offset' => '+00:00',
        'action' => 'ADD',
        'biz_step' => 'urn:epcglobal:cbv:bizstep:packing',
        'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
        'read_point_gln' => $readGln,
        'biz_location_gln' => $readGln,
    ]);

    attachEventEpc((int) $event->getKey(), (int) $parent->getKey(), 'parentID');
    foreach ($children as $child) {
        attachEventEpc((int) $event->getKey(), (int) $child->getKey(), 'childEPCs');
        AggregationLink::query()->firstOrCreate(
            [
                'parent_epc_id' => (int) $parent->getKey(),
                'child_epc_id' => (int) $child->getKey(),
                'valid_to' => null,
            ],
            [
                'established_by_event_id' => (int) $event->getKey(),
                'link_type' => 'aggregation',
                'valid_from' => now()->subHour(),
            ],
        );
    }

    return $event;
}

function ensureHierarchyAtSite(Site $site): array
{
    $existing = Epc::query()->where('epc_uri', PALLET_URI)->first();
    if ($existing !== null && app(ShippableEpcsAtSite::class)->contains(SHIP_FROM_SITE_ID, (int) $existing->getKey())) {
        $pallet = $existing;
        $case = Epc::query()->where('epc_uri', CASE_URI)->firstOrFail();
        $bottle1 = Epc::query()->where('epc_uri', BOTTLE1_URI)->firstOrFail();
        $bottle2 = Epc::query()->where('epc_uri', BOTTLE2_URI)->firstOrFail();

        return compact('pallet', 'case', 'bottle1', 'bottle2');
    }

    $document = EpcisDocument::query()->firstOrCreate(
        ['original_filename' => HIERARCHY_DOC_FILENAME, 'direction' => 'inbound'],
        [
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'received_at' => now(),
            'status' => 'validated',
        ],
    );

    $mfgGln = '0301160000009';
    $siteGln = (string) $site->gln;

    $bottle1 = Epc::query()->firstOrCreate(['epc_uri' => BOTTLE1_URI], Epc::materializeAttributesFromUri(BOTTLE1_URI));
    $bottle2 = Epc::query()->firstOrCreate(['epc_uri' => BOTTLE2_URI], Epc::materializeAttributesFromUri(BOTTLE2_URI));
    $case = Epc::query()->firstOrCreate(['epc_uri' => CASE_URI], Epc::materializeAttributesFromUri(CASE_URI));
    $pallet = Epc::query()->firstOrCreate(['epc_uri' => PALLET_URI], Epc::materializeAttributesFromUri(PALLET_URI));

    createObjectEvent($document, $bottle1, 'urn:epcglobal:cbv:bizstep:commissioning', $mfgGln, $mfgGln);
    createObjectEvent($document, $bottle2, 'urn:epcglobal:cbv:bizstep:commissioning', $mfgGln, $mfgGln);
    createObjectEvent($document, $case, 'urn:epcglobal:cbv:bizstep:commissioning', $mfgGln, $mfgGln);
    createAggregationEvent($document, $case, [$bottle1, $bottle2], $mfgGln);
    createObjectEvent($document, $pallet, 'urn:epcglobal:cbv:bizstep:commissioning', $mfgGln, $mfgGln);
    createAggregationEvent($document, $pallet, [$case], $mfgGln);

    $receive = EpcisEvent::query()->create([
        'document_id' => $document->getKey(),
        'event_id' => 'urn:uuid:'.(string) Str::uuid(),
        'event_type' => 'ObjectEvent',
        'event_time' => now()->subMinutes(30),
        'record_time' => now()->subMinutes(30),
        'event_timezone_offset' => '+00:00',
        'action' => 'OBSERVE',
        'biz_step' => 'urn:epcglobal:cbv:bizstep:receiving',
        'disposition' => 'urn:epcglobal:cbv:disp:in_progress',
        'read_point_gln' => $siteGln,
        'biz_location_gln' => $siteGln,
    ]);

    foreach ([$pallet, $case, $bottle1, $bottle2] as $epc) {
        attachEventEpc((int) $receive->getKey(), (int) $epc->getKey(), 'epcList');
    }

    return compact('pallet', 'case', 'bottle1', 'bottle2');
}

$tenant = Tenant::query()->findOrFail(DEMO2_TENANT_ID);
tenancy()->initialize($tenant);

$settings = is_array($tenant->settings) ? $tenant->settings : [];
data_set($settings, 'features.client_portal_v2', true);
$tenant->forceFill([
    'settings' => $settings,
    'company_prefix' => null,
    'default_ship_from_site_id' => SHIP_FROM_SITE_ID,
])->save();

$site = Site::query()->findOrFail(SHIP_FROM_SITE_ID);

$partner = TradingPartner::query()->firstOrCreate(
    ['name' => PARTNER_NAME],
    [
        'gln' => uniqueGln('037014'),
        'partner_type' => PartnerType::Pharmacy,
        'email' => PORTAL_EMAIL,
        'country_code' => 'US',
        'is_active' => true,
    ],
);
if (! $partner->sgln) {
    $partner->forceFill(['sgln' => Sgln::toUrn((string) $partner->gln, 6)])->save();
}

$shipTo = $partner->sites()->first();
if (! $shipTo) {
    $siteGln = uniqueGln('037014');
    $shipTo = Site::query()->create([
        'trading_partner_id' => (int) $partner->getKey(),
        'name' => 'Joel Test Ship-To',
        'gln' => $siteGln,
        'sgln' => Sgln::toUrn($siteGln, 6),
        'street_address' => '123 Test Market St',
        'city' => 'Austin',
        'state' => 'TX',
        'zipcode' => '73301',
        'country_code' => 'US',
        'is_active' => true,
        'is_organization_facility' => false,
    ]);
}

if (! AtpLicense::query()->where('site_id', $shipTo->id)->exists()) {
    AtpLicense::query()->create([
        'site_id' => (int) $shipTo->getKey(),
        'facility_type' => FacilityType::Wdd,
        'license_number' => 'JOEL-PORTAL-'.random_int(100000, 999999),
        'license_state' => 'TX',
        'license_expiration_date' => now()->addYear(),
        'reporting_year' => (int) now()->year,
    ]);
}

$portalConn = OutboundConnection::query()->firstOrCreate(
    ['name' => 'Joel Client Portal'],
    [
        'serialization_provider' => SerializationProvider::Other,
        'transport' => OutboundTransport::Portal,
        'is_active' => true,
        'credentials' => [],
        'settings' => [
            'notify_on_publish' => true,
            'invite_emails' => [PORTAL_EMAIL],
        ],
    ],
);
$portalConn->forceFill([
    'is_active' => true,
    'settings' => [
        'notify_on_publish' => true,
        'invite_emails' => [PORTAL_EMAIL],
    ],
])->save();

$org = app(EnsurePortalOrganization::class)->handle($partner);
$portalUser = PortalUser::query()->firstOrCreate(
    ['email' => PORTAL_EMAIL],
    ['is_active' => true, 'name' => 'Joel Test'],
);
if (! $portalUser->organizations()->where('portal_organizations.id', $org->getKey())->exists()) {
    $role = $org->users()->count() === 0 ? 'admin' : 'member';
    $org->users()->attach($portalUser->getKey(), ['role' => $role]);
}

$hierarchy = ensureHierarchyAtSite($site);
$pallet = $hierarchy['pallet'];

$user = User::query()->where('email', 'owner@demo.test')->firstOrFail();
auth()->login($user);

$session = app(OpenOutboundShippingSession::class)->handle(SHIP_FROM_SITE_ID, (int) $user->getKey());
app(ConfirmOutboundShippingScan::class)->handle($session, (string) $pallet->sscc18, (int) $user->getKey());

app(UpdateOutboundShippingParty::class)->handle($session->fresh(), [
    'trading_partner_id' => (int) $partner->getKey(),
    'ship_to_site_id' => (int) $shipTo->getKey(),
    'ship_to_gln' => (string) $shipTo->gln,
    'outbound_connection_id' => (int) $portalConn->getKey(),
]);

app(UpdateOutboundShippingReferences::class)->handle($session->fresh(), [
    'asn_number' => 'ASN-JOEL-PORTAL-001',
    'customer_po' => 'PO-JOEL-PORTAL-001',
    'dscsa_affirm' => true,
]);

$session = app(CompleteOutboundShippingSession::class)->handle($session->fresh(), (int) $user->getKey());

$document = $session->epcisDocument;
$publication = $document
    ? PortalPublication::query()->where('epcis_document_id', $document->getKey())->first()
    : null;

echo json_encode([
    'session_id' => $session->getKey(),
    'session_status' => $session->status,
    'pallet_sscc18' => $pallet->sscc18,
    'case_uri' => CASE_URI,
    'bottle_uris' => [BOTTLE1_URI, BOTTLE2_URI],
    'event_counts' => [
        'pallet' => DB::table('event_epcs')->where('epc_id', $pallet->getKey())->count(),
        'case' => DB::table('event_epcs')->where('epc_id', $hierarchy['case']->getKey())->count(),
        'bottle1' => DB::table('event_epcs')->where('epc_id', $hierarchy['bottle1']->getKey())->count(),
        'bottle2' => DB::table('event_epcs')->where('epc_id', $hierarchy['bottle2']->getKey())->count(),
    ],
    'epcis_document_id' => $document?->getKey(),
    'transmission_status' => $document?->transmission_status,
    'portal_publication_id' => $publication?->getKey(),
    'portal_login_url' => 'https://demo2.internal.vatengi.com/client-portal/login',
    'portal_email' => PORTAL_EMAIL,
    'partner' => $partner->name,
    'outbound_connection' => $portalConn->name,
], JSON_PRETTY_PRINT).PHP_EOL;

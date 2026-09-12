<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Enums\FacilityType;
use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Models\AtpLicense;
use App\Models\OutboundConnection;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Sgln;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\Tenancy\TenantKillSwitches;
use Illuminate\Contracts\Console\Kernel;

const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

function gs1CheckDigit(string $bodyWithoutCheck): string
{
    $sum = 0;
    $digits = str_split(strrev($bodyWithoutCheck));

    foreach ($digits as $index => $digit) {
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

$tenant = Tenant::query()->findOrFail(DEMO2_TENANT_ID);
tenancy()->initialize($tenant);

// Debug Ship Site uses a 030116 GLN; clear prefix gate so demo stock can ship from site 6693.
$tenant->company_prefix = null;
$tenant->default_ship_from_site_id = 6693;
$tenant->save();

$partner = TradingPartner::query()->firstOrCreate(
    ['name' => 'Joel Test Dispenser'],
    [
        'gln' => uniqueGln('037014'),
        'partner_type' => PartnerType::Pharmacy,
        'email' => 'joel.makoni@gmail.com',
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

$emailConn = OutboundConnection::query()
    ->where('transport', OutboundTransport::Email)
    ->where('name', 'Joel EPCIS Email Test')
    ->first();

if (! $emailConn) {
    $emailConn = OutboundConnection::query()->create([
        'name' => 'Joel EPCIS Email Test',
        'serialization_provider' => SerializationProvider::Other,
        'transport' => OutboundTransport::Email,
        'is_active' => true,
        'is_default' => false,
        'credentials' => [],
        'settings' => [
            'to_emails' => ['joel.makoni@gmail.com'],
            'subject_template' => 'EPCIS transaction information — ASN {{asn}}',
            'max_attachment_mb' => 15,
        ],
    ]);
} else {
    $settings = $emailConn->settings ?? [];
    $settings['to_emails'] = ['joel.makoni@gmail.com'];
    $emailConn->forceFill(['is_active' => true, 'settings' => $settings])->save();
}

if (! AtpLicense::query()->where('site_id', $shipTo->id)->exists()) {
    AtpLicense::query()->create([
        'site_id' => (int) $shipTo->getKey(),
        'facility_type' => FacilityType::Wdd,
        'license_number' => 'JOEL-TEST-'.random_int(100000, 999999),
        'license_state' => 'TX',
        'license_expiration_date' => now()->addYear(),
        'reporting_year' => (int) now()->year,
    ]);
}

$shippable = app(ShippableEpcsAtSite::class);
$epc = $shippable->query(6693)->whereNotNull('sscc18')->first();

echo json_encode([
    'partner_id' => $partner->id,
    'partner_name' => $partner->name,
    'partner_gln' => $partner->gln,
    'partner_sgln' => $partner->sgln,
    'ship_to_site_id' => $shipTo->id,
    'ship_to_gln' => $shipTo->gln,
    'ship_to_sgln' => $shipTo->sgln,
    'email_connection_id' => $emailConn->id,
    'email_connection_name' => $emailConn->name,
    'to_emails' => $emailConn->settings['to_emails'] ?? [],
    'ship_from_site_id' => 6693,
    'sscc18' => $epc?->sscc18,
    'sscc_uri' => $epc?->epc_uri,
    'mail_mailer' => config('mail.default'),
    'outbound_epcis_killed' => TenantKillSwitches::forTenant(tenant())->outboundEpcisKilled(),
], JSON_PRETTY_PRINT).PHP_EOL;

<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Atp\AtpCredentialParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartnerAtpDepthTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $licenseIds = [];

    #[Test]
    public function atp_status_rolls_up_worst_of_partner_and_site_licenses(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->makePartner();

            $this->assertSame('unknown', $partner->atpStatus());

            $pending = AtpLicense::query()->create([
                'trading_partner_id' => $partner->getKey(),
                'license_number' => 'PENDING-1',
                'license_country' => 'US',
                'license_state' => 'FL',
                'license_expiration_date' => now()->addYear(),
                'verification_status' => AtpLicense::VERIFICATION_PENDING,
                'is_active' => true,
            ]);
            $this->licenseIds[] = (int) $pending->getKey();

            $this->assertSame('pending', $partner->fresh()->atpStatus());

            $pending->forceFill(['verification_status' => AtpLicense::VERIFICATION_VERIFIED])->save();
            $this->assertSame('verified', $partner->fresh()->atpStatus());

            $pending->forceFill(['license_expiration_date' => now()->addDays(10)])->save();
            $this->assertSame('expiring', $partner->fresh()->atpStatus());

            $pending->forceFill(['license_expiration_date' => now()->subDay()])->save();
            $this->assertSame('expired', $partner->fresh()->atpStatus());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function signed_link_submission_lands_as_pending_verification_with_document(): void
    {
        Storage::fake('local');
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->makePartner();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);

            $url = URL::temporarySignedRoute(
                'tenant.partner-license-update.store',
                now()->addDays(3),
                ['partner' => $partner->getKey()],
            );

            tenancy()->end();

            $response = $this->post($url, [
                'license_number' => 'FL-12345',
                'license_state' => 'FL',
                'license_expiration_date' => now()->addYear()->toDateString(),
                'contact_email' => 'licensing@partner.example',
                'document' => UploadedFile::fake()->create('license.pdf', 128, 'application/pdf'),
            ]);

            $response->assertRedirect();

            $license = AtpLicense::query()
                ->where('trading_partner_id', $partner->getKey())
                ->where('license_number', 'FL-12345')
                ->first();

            $this->assertNotNull($license);
            $this->licenseIds[] = (int) $license->getKey();
            $this->assertTrue($license->isPendingVerification());
            $this->assertSame('license.pdf', $license->document_original_name);
            $this->assertNotNull($license->document_path);

            $this->assertSame('pending', $partner->fresh()->atpStatus());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function unsigned_or_tampered_link_is_rejected(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->makePartner();

            URL::forceRootUrl('http://'.self::DEMO2_DOMAIN);

            $plain = 'http://'.self::DEMO2_DOMAIN.'/partner-license-update/'.$partner->getKey();

            tenancy()->end();

            $response = $this->post($plain, [
                'license_number' => 'FL-99999',
                'license_state' => 'FL',
                'license_expiration_date' => now()->addYear()->toDateString(),
                'document' => UploadedFile::fake()->create('license.pdf', 64, 'application/pdf'),
            ]);

            $response->assertForbidden();

            $this->assertDatabaseMissing('atp_licenses', [
                'trading_partner_id' => $partner->getKey(),
                'license_number' => 'FL-99999',
            ]);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function atp_credential_parser_extracts_claims_without_verification(): void
    {
        $payload = base64_encode(json_encode([
            'iss' => 'did:web:oci.example',
            'sub' => 'urn:epc:id:sgln:0300001.00001.0',
            'exp' => now()->addHour()->timestamp,
        ]));

        $jwt = 'header.'.strtr(rtrim($payload, '='), '+/', '-_').'.signature';

        $parsed = AtpCredentialParser::parse('Bearer '.$jwt);

        $this->assertNotNull($parsed);
        $this->assertSame('did:web:oci.example', $parsed['issuer']);
        $this->assertSame('0300001000010', $parsed['subject_gln']);
        $this->assertNotNull($parsed['expires_at']);

        $this->assertNull(AtpCredentialParser::parse('not-a-jwt'));
        $this->assertNull(AtpCredentialParser::parse(''));
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function makePartner(): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => 'ATP Depth '.Str::random(4),
            'gln' => null,
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ]);

        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function cleanup(): void
    {
        URL::forceRootUrl('');

        if (! tenancy()->initialized) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

            if ($tenant !== null && ($this->partnerIds !== [] || $this->licenseIds !== [])) {
                tenancy()->initialize($tenant);
            }
        }

        if (tenancy()->initialized) {
            if ($this->licenseIds !== []) {
                AtpLicense::query()->whereIn('id', $this->licenseIds)->delete();
                $this->licenseIds = [];
            }

            if ($this->partnerIds !== []) {
                TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
                $this->partnerIds = [];
            }

            tenancy()->end();
        }
    }
}

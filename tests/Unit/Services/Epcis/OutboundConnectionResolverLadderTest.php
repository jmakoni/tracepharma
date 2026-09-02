<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis;

use App\Enums\OutboundTransport;
use App\Enums\PartnerType;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Services\Epcis\OutboundConnectionResolver;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundConnectionResolverLadderTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $connectionIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    /** @var list<int> */
    private array $temporarilyDeactivatedIds = [];

    #[Test]
    public function b2b_partner_connection_wins_over_active_portal(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Ladder B2B wins');

            $portal = OutboundConnection::query()->create([
                'name' => 'Active portal '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => true,
                'settings' => ['notify_on_publish' => true],
            ]);
            $this->connectionIds[] = (int) $portal->getKey();

            $https = OutboundConnection::query()->create([
                'name' => 'Partner HTTPS '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => false,
                'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $https->getKey();

            $resolved = app(OutboundConnectionResolver::class)
                ->resolveWithLadder((int) $partner->getKey());

            $this->assertNotNull($resolved);
            $this->assertSame($https->getKey(), $resolved->getKey());
            $this->assertSame(OutboundTransport::Https, $resolved->transport);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function when_no_b2b_active_portal_is_returned_by_resolve_with_ladder(): void
    {
        $this->initializeDemo2Tenant();

        try {
            // Demo2 may have leftover global AS2/HTTPS; ladder prefers those over portal.
            $this->deactivateGlobalB2bConnections();

            $partner = $this->createPartner('Ladder portal fallback');

            $portal = OutboundConnection::query()->create([
                'name' => 'Partner portal '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'settings' => ['notify_on_publish' => true],
            ]);
            $this->connectionIds[] = (int) $portal->getKey();

            $resolved = app(OutboundConnectionResolver::class)
                ->resolveWithLadder((int) $partner->getKey());

            $this->assertNotNull($resolved);
            $this->assertSame($portal->getKey(), $resolved->getKey());
            $this->assertSame(OutboundTransport::Portal, $resolved->transport);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function active_email_is_never_returned_by_resolve_with_ladder_even_if_default(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->deactivateGlobalB2bConnections();
            // Avoid an existing active portal short-circuiting the "email excluded" case.
            $this->deactivateActivePortalConnections();

            $partner = $this->createPartner('Ladder email excluded');

            $email = OutboundConnection::query()->create([
                'name' => 'Partner email default '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Email,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => true,
                'settings' => [
                    'to_emails' => ['ops@example.com'],
                    'max_attachment_mb' => 15,
                ],
            ]);
            $this->connectionIds[] = (int) $email->getKey();

            $resolved = app(OutboundConnectionResolver::class)
                ->resolveWithLadder((int) $partner->getKey());

            $this->assertNull($resolved);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function resolve_still_returns_partner_scoped_email_when_it_is_default_active(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Explicit email resolve');

            $email = OutboundConnection::query()->create([
                'name' => 'Partner email only '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Email,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'is_default' => true,
                'settings' => [
                    'to_emails' => ['ops@example.com'],
                    'max_attachment_mb' => 15,
                ],
            ]);
            $this->connectionIds[] = (int) $email->getKey();

            $resolved = app(OutboundConnectionResolver::class)
                ->resolve((int) $partner->getKey());

            $this->assertNotNull($resolved);
            $this->assertSame($email->getKey(), $resolved->getKey());
            $this->assertSame(OutboundTransport::Email, $resolved->transport);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function partner_without_b2b_skips_global_b2b_for_ladder(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $partner = $this->createPartner('Portal only partner');

            $globalHttps = OutboundConnection::query()->create([
                'name' => 'Global HTTPS '.Str::random(4),
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::Https,
                'trading_partner_id' => null,
                'is_active' => true,
                'is_default' => true,
                'settings' => ['endpoint_url' => 'https://global.example/epcis'],
            ]);
            $this->connectionIds[] = (int) $globalHttps->getKey();

            $portal = OutboundConnection::query()->create([
                'name' => 'Partner portal '.Str::random(4),
                'serialization_provider' => SerializationProvider::Other,
                'transport' => OutboundTransport::Portal,
                'trading_partner_id' => $partner->getKey(),
                'is_active' => true,
                'settings' => ['notify_on_publish' => true],
            ]);
            $this->connectionIds[] = (int) $portal->getKey();

            $resolved = app(OutboundConnectionResolver::class)
                ->resolveWithLadder((int) $partner->getKey());

            $this->assertNotNull($resolved);
            $this->assertSame($portal->getKey(), $resolved->getKey());
            $this->assertSame(OutboundTransport::Portal, $resolved->transport);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    private function createPartner(string $name): TradingPartner
    {
        $partner = TradingPartner::query()->create([
            'name' => $name.' '.uniqid(),
            'gln' => $this->uniqueGln(),
            'partner_type' => PartnerType::Pharmacy,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        return $partner;
    }

    private function uniqueGln(): string
    {
        $base = str_pad((string) random_int(100000000000, 899999999999), 12, '0', STR_PAD_LEFT);

        return $base.$this->checkDigit($base);
    }

    private function checkDigit(string $base12): string
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base12[$i];
            $sum += ($i % 2 === 0) ? $digit * 3 : $digit;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
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

    private function deactivateGlobalB2bConnections(): void
    {
        $ids = OutboundConnection::query()
            ->where('is_active', true)
            ->whereNull('trading_partner_id')
            ->whereIn('transport', [
                OutboundTransport::Https,
                OutboundTransport::Sftp,
                OutboundTransport::As2,
            ])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        OutboundConnection::query()->whereIn('id', $ids)->update(['is_active' => false]);
        $this->temporarilyDeactivatedIds = array_values(array_unique([
            ...$this->temporarilyDeactivatedIds,
            ...$ids,
        ]));
    }

    private function deactivateActivePortalConnections(): void
    {
        $ids = OutboundConnection::query()
            ->where('is_active', true)
            ->where('transport', OutboundTransport::Portal)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        OutboundConnection::query()->whereIn('id', $ids)->update(['is_active' => false]);
        $this->temporarilyDeactivatedIds = array_values(array_unique([
            ...$this->temporarilyDeactivatedIds,
            ...$ids,
        ]));
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->connectionIds !== []) {
            OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            $this->connectionIds = [];
        }

        if ($this->temporarilyDeactivatedIds !== []) {
            OutboundConnection::query()
                ->whereIn('id', $this->temporarilyDeactivatedIds)
                ->update(['is_active' => true]);
            $this->temporarilyDeactivatedIds = [];
        }

        if ($this->partnerIds !== []) {
            TradingPartner::query()->whereIn('id', $this->partnerIds)->delete();
            $this->partnerIds = [];
        }
    }
}

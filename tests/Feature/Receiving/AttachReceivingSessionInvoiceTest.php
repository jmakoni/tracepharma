<?php

namespace Tests\Feature\Receiving;

use App\Actions\Receiving\AttachReceivingSessionInvoice;
use App\Actions\Receiving\OpenScanFirstReceivingSession;
use App\Enums\ReceivingSessionKind;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\ReceivingSessions\Pages\ViewReceivingSession;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Gs1\Gtin;
use App\Support\Receiving\EligibleReceiveSites;
use App\Support\TenantSettings;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PreparesDemo2ReceivingState;
use Tests\TestCase;

class AttachReceivingSessionInvoiceTest extends TestCase
{
    use PreparesDemo2ReceivingState;

    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $sessionId = null;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<string> */
    private array $storedInvoicePaths = [];

    #[Test]
    public function scan_first_attach_stores_path_sha256_and_original_filename(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $contents = 'paper invoice '.random_int(100000, 999999);
            $tmp = $this->writeTempInvoice($contents, 'packing-slip.pdf');

            try {
                $updated = app(AttachReceivingSessionInvoice::class)->handle(
                    $session,
                    $tmp,
                    'packing-slip.pdf',
                    (int) $owner->getKey(),
                );
            } finally {
                @unlink($tmp);
            }

            $this->assertSame('packing-slip.pdf', $updated->invoice_original_filename);
            $this->assertSame(hash('sha256', $contents), $updated->invoice_sha256);
            $this->assertSame(64, strlen((string) $updated->invoice_sha256));
            $this->assertNotEmpty($updated->invoice_path);
            $this->assertNotEmpty($updated->invoice_disk);
            $this->storedInvoicePaths[] = $updated->invoice_disk.'|'.$updated->invoice_path;
            $this->assertTrue(Storage::disk($updated->invoice_disk)->exists($updated->invoice_path));
            $this->assertSame($contents, Storage::disk($updated->invoice_disk)->get($updated->invoice_path));

            Livewire::test(ViewReceivingSession::class, ['record' => $session->getKey()])
                ->assertSee('packing-slip.pdf')
                ->assertSee('Attach invoice');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function replace_keeps_new_blob_then_deletes_old_and_truncates_filename(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);

            $session = app(OpenScanFirstReceivingSession::class)->handle();
            $this->sessionId = (int) $session->getKey();

            $first = $this->writeTempInvoice('first invoice '.random_int(100000, 999999), 'first.pdf');
            $secondContents = 'second invoice '.random_int(100000, 999999);
            $longName = str_repeat('invoice', 40).'.pdf';
            $this->assertGreaterThan(255, strlen($longName));
            $second = $this->writeTempInvoice($secondContents, 'second.pdf');

            try {
                $afterFirst = app(AttachReceivingSessionInvoice::class)->handle(
                    $session,
                    $first,
                    'first.pdf',
                    (int) $owner->getKey(),
                );
                $oldDisk = (string) $afterFirst->invoice_disk;
                $oldPath = (string) $afterFirst->invoice_path;
                $this->assertTrue(Storage::disk($oldDisk)->exists($oldPath));

                $afterSecond = app(AttachReceivingSessionInvoice::class)->handle(
                    $afterFirst,
                    $second,
                    $longName,
                    (int) $owner->getKey(),
                );
            } finally {
                @unlink($first);
                @unlink($second);
            }

            $this->storedInvoicePaths[] = $afterSecond->invoice_disk.'|'.$afterSecond->invoice_path;
            $this->assertNotSame($oldPath, $afterSecond->invoice_path);
            $this->assertTrue(Storage::disk($afterSecond->invoice_disk)->exists($afterSecond->invoice_path));
            $this->assertFalse(Storage::disk($oldDisk)->exists($oldPath));
            $this->assertSame($secondContents, Storage::disk($afterSecond->invoice_disk)->get($afterSecond->invoice_path));
            $this->assertSame(hash('sha256', $secondContents), $afterSecond->invoice_sha256);
            $this->assertLessThanOrEqual(255, strlen((string) $afterSecond->invoice_original_filename));
            $this->assertStringEndsWith('.pdf', (string) $afterSecond->invoice_original_filename);
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function inbound_asn_attach_is_rejected(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = User::factory()->create();
            $owner->assignRole(TenantRole::Owner->value);
            $this->userIds[] = (int) $owner->getKey();
            $this->actingAs($owner);

            $session = ReceivingSession::query()->create([
                'session_kind' => ReceivingSessionKind::InboundAsn,
                'epcis_document_id' => null,
                'site_id' => $this->resolveEligibleReceiveSiteId(),
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionId = (int) $session->getKey();

            $tmp = $this->writeTempInvoice('asn invoice', 'asn-invoice.pdf');

            try {
                $this->expectException(DomainException::class);
                app(AttachReceivingSessionInvoice::class)->handle(
                    $session,
                    $tmp,
                    'asn-invoice.pdf',
                    (int) $owner->getKey(),
                );
            } finally {
                @unlink($tmp);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function user_without_session_site_access_cannot_attach_invoice(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $owner = $this->createUniqueUser();
            $owner->assignRole(TenantRole::Owner->value);
            $this->actingAs($owner);

            [$siteA, $siteB] = $this->createReceiveSites();

            $session = app(OpenScanFirstReceivingSession::class)->handle((int) $siteA->getKey());
            $this->sessionId = (int) $session->getKey();

            $restricted = $this->createUniqueUser();
            $restricted->assignRole(TenantRole::ReceivingTechnician->value);
            $restricted->syncSites([(int) $siteB->getKey()], (int) $siteB->getKey());
            $this->userIds[] = (int) $restricted->getKey();
            $this->actingAs($restricted);

            $tmp = $this->writeTempInvoice('restricted invoice', 'restricted.pdf');

            try {
                $this->expectException(AuthorizationException::class);
                app(AttachReceivingSessionInvoice::class)->handle(
                    $session->fresh(),
                    $tmp,
                    'restricted.pdf',
                    (int) $restricted->getKey(),
                );
            } finally {
                @unlink($tmp);
            }
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function writeTempInvoice(string $contents, string $basename): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'recv_inv_');
        $this->assertNotFalse($tmp);
        $named = $tmp.'_'.$basename;
        $this->assertNotFalse(rename($tmp, $named));
        file_put_contents($named, $contents);

        return $named;
    }

    /**
     * @return array{0: Site, 1: Site}
     */
    private function createReceiveSites(): array
    {
        $siteA = Site::query()->create([
            'name' => 'Invoice Receive A '.str()->random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $siteA->getKey();

        $siteB = Site::query()->create([
            'name' => 'Invoice Receive B '.str()->random(6),
            'gln' => $this->uniqueGln(),
            'is_active' => true,
            'is_headquarters' => false,
            'trading_partner_id' => null,
            'is_organization_facility' => true,
        ]);
        $this->siteIds[] = (int) $siteB->getKey();

        return [$siteA, $siteB];
    }

    private function uniqueGln(): string
    {
        $prefix = TenantSettings::forTenant(tenant())->companyPrefix() ?: '03';
        $fill = max(1, 12 - strlen($prefix));

        do {
            $body = substr($prefix.str_pad((string) random_int(0, (int) str_repeat('9', $fill)), $fill, '0', STR_PAD_LEFT), 0, 12);
            $gln = $body.Gtin::checkDigit($body);
        } while (Site::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function resolveEligibleReceiveSiteId(): ?int
    {
        $sites = app(EligibleReceiveSites::class)->options();

        return $sites === [] ? null : (int) array_key_first($sites);
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

        $this->prepareDemo2ReceivingState();

        return $tenant;
    }

    private function createUniqueUser(): User
    {
        $user = User::factory()->create([
            'email' => 'invoice-'.Str::uuid().'@example.test',
        ]);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->storedInvoicePaths as $stored) {
            [$disk, $path] = explode('|', $stored, 2);
            if ($path !== '') {
                Storage::disk($disk)->delete($path);
            }
        }
        $this->storedInvoicePaths = [];

        if ($this->sessionId !== null) {
            ReceivingSession::query()->whereKey($this->sessionId)->delete();
            $this->sessionId = null;
        }

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }

        if ($this->siteIds !== []) {
            Site::query()->whereIn('id', $this->siteIds)->delete();
            $this->siteIds = [];
        }

        $tenant->save();

        tenancy()->end();
    }
}

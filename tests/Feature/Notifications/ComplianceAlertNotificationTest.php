<?php

namespace Tests\Feature\Notifications;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Notifications\ComplianceAlertNotification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComplianceAlertNotificationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    #[Test]
    public function to_mail_uses_the_tenant_domain_when_tenancy_is_not_initialized(): void
    {
        $this->ensureDemo2Domain();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->assertFalse(tenancy()->initialized);

        $mail = (new ComplianceAlertNotification(
            '2 tracing request(s) past SLA',
            "#1 — Overdue\n#2 — Also overdue",
            '/tracing-requests',
            self::DEMO2_TENANT_ID,
        ))->toMail((object) ['email' => 'owner@demo.test']);

        $this->assertSame(
            'https://'.self::DEMO2_DOMAIN.'/tracing-requests',
            $mail->actionUrl,
        );
        $this->assertContains('#1 — Overdue', $this->introLines($mail));
        $this->assertContains('#2 — Also overdue', $this->introLines($mail));
    }

    #[Test]
    public function to_mail_truncates_to_ten_lines_and_appends_overflow(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = 'License '.$i;
        }

        $mail = (new ComplianceAlertNotification(
            '12 ATP license(s) expired or expiring',
            implode("\n", $lines),
            '/sites',
            null,
            12,
        ))->toMail((object) ['email' => 'owner@demo.test']);

        $intro = $this->introLines($mail);

        $this->assertContains('License 1', $intro);
        $this->assertContains('License 10', $intro);
        $this->assertNotContains('License 11', $intro);
        $this->assertNotContains('License 12', $intro);
        $this->assertTrue(
            collect($intro)->contains(fn (string $line): bool => str_contains($line, '…and 2 more.')),
        );
    }

    /**
     * @return list<string>
     */
    private function introLines(object $mail): array
    {
        $lines = [];

        foreach ($mail->introLines as $line) {
            $lines[] = is_array($line) ? implode(' ', $line) : (string) $line;
        }

        return $lines;
    }

    private function ensureDemo2Domain(): void
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
        }

        $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\CustomerPortalShipNotification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerPortalShipNotificationTest extends TestCase
{
    #[Test]
    public function mail_includes_portal_action_and_asn(): void
    {
        $mail = (new CustomerPortalShipNotification(
            partnerName: 'Buyer Pharmacy',
            portalUrl: 'https://demo.example/customer-portal/abc?signature=x',
            asnNumber: 'ASN-42',
            customerPo: 'PO-9',
            tenantName: 'Demo Wholesaler',
        ))->toMail((object) ['email' => 'buyer@example.com']);

        $this->assertSame('EPCIS / TI available for download', $mail->subject);
        $this->assertSame('https://demo.example/customer-portal/abc?signature=x', $mail->actionUrl);
        $this->assertTrue(
            collect($mail->introLines)->contains(fn ($line): bool => str_contains((string) (is_array($line) ? implode(' ', $line) : $line), 'ASN-42')),
        );
    }
}

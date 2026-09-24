<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Mail;

use App\Support\Mail\NonDeliverableRecipient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NonDeliverableRecipientTest extends TestCase
{
    #[Test]
    #[DataProvider('blockedAddresses')]
    public function it_blocks_reserved_and_test_recipient_domains(string $email): void
    {
        $this->assertTrue(NonDeliverableRecipient::blocks($email), $email);
    }

    #[Test]
    #[DataProvider('allowedAddresses')]
    public function it_allows_real_deliverable_domains(string $email): void
    {
        $this->assertFalse(NonDeliverableRecipient::blocks($email), $email);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            'example.com' => ['owner@example.com'],
            'example.net' => ['qa@example.net'],
            'example.org' => ['ops@example.org'],
            'example subdomain' => ['user@mail.example.com'],
            'dot test' => ['owner@demo.test'],
            'tracepharma.test' => ['admin@tracepharma.test'],
            'example.test' => ['owner-slug@example.test'],
            'dot example tld' => ['a@foo.example'],
            'dot invalid' => ['nobody@nowhere.invalid'],
            'dot localhost' => ['dev@app.localhost'],
            'localhost host' => ['root@localhost'],
            'dot local' => ['printer@office.local'],
            'display name' => ['Demo Owner <owner@demo.test>'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedAddresses(): array
    {
        return [
            'customer' => ['alex@acme-pharmacy.com'],
            'tracepharma.io' => ['ops@tracepharma.io'],
            'internal vatengi' => ['jmakoni@internal.vatengi.com'],
            'test.com is real' => ['abuse@test.com'],
        ];
    }
}

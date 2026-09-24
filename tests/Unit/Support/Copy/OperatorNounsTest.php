<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Copy;

use App\Enums\ExceptionDisposition;
use App\Enums\ReceivingSessionKind;
use App\Support\Copy\OperatorNouns;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OperatorNounsTest extends TestCase
{
    #[Test]
    public function asn_badge_and_floor_labels_are_context_true(): void
    {
        $this->assertSame('ASN receive', ReceivingSessionKind::InboundAsn->badgeLabel());
        $this->assertSame(OperatorNouns::ASN_RECEIVE_BADGE, ReceivingSessionKind::InboundAsn->badgeLabel());
        $this->assertSame('Partially Received', OperatorNouns::FLOOR_PARTIALLY_RECEIVED);
        $this->assertSame('Hold cleared for distribution', ExceptionDisposition::Cleared->label());
        $this->assertStringNotContainsString('ASN clear', ExceptionDisposition::Cleared->label());
    }
}

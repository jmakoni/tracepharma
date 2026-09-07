<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Integrations;

use App\Enums\OutboundConnectionKind;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Support\Integrations\OutboundSendPreset;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundSendPresetTest extends TestCase
{
    #[Test]
    public function lspedia_allows_https_sftp_as2_not_email(): void
    {
        $allowed = OutboundSendPreset::allowedTransportsFor(
            OutboundConnectionKind::ProviderHub,
            SerializationProvider::Lspedia,
        );

        $this->assertContains(OutboundTransport::Https, $allowed);
        $this->assertContains(OutboundTransport::As2, $allowed);
        $this->assertNotContains(OutboundTransport::Email, $allowed);
        $this->assertNotContains(OutboundTransport::Portal, $allowed);

        $this->expectException(DomainException::class);
        OutboundSendPreset::assertTransportAllowed(
            OutboundConnectionKind::ProviderHub,
            SerializationProvider::Lspedia,
            OutboundTransport::Email,
        );
    }

    #[Test]
    public function unitrace_defaults_to_https(): void
    {
        $this->assertSame(
            OutboundTransport::Https,
            OutboundSendPreset::defaultTransportFor(
                OutboundConnectionKind::ProviderHub,
                SerializationProvider::UniTrace,
            ),
        );
    }

    #[Test]
    public function infer_kind_for_network_with_partners(): void
    {
        $this->assertSame(
            OutboundConnectionKind::ProviderHub,
            OutboundSendPreset::inferKind(
                SerializationProvider::Lspedia,
                OutboundTransport::Https,
                2,
            ),
        );
    }
}

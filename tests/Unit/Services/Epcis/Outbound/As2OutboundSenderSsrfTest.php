<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Services\Epcis\Outbound\As2OutboundSender;
use App\Services\Epcis\Outbound\As2SmimeEnvelope;
use App\Support\Integrations\As2MdnDispositionParser;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class As2OutboundSenderSsrfTest extends TestCase
{
    private function sender(): As2OutboundSender
    {
        return new As2OutboundSender(
            app(As2SmimeEnvelope::class),
            app(As2MdnDispositionParser::class),
        );
    }

    private function connection(string $as2Url): OutboundConnection
    {
        return new OutboundConnection([
            'settings' => [
                'as2_url' => $as2Url,
                'as2_from' => 'TP-FROM',
                'as2_to' => 'TP-TO',
            ],
            'credentials' => [],
        ]);
    }

    #[Test]
    public function rejects_link_local_metadata_as2_url(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private or metadata host');

        $this->sender()->send($this->connection('https://169.254.169.254/'), '<epcis/>', 'shipment.xml');
    }

    #[Test]
    public function rejects_private_rfc1918_as2_url(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private or metadata host');

        $this->sender()->send($this->connection('https://10.0.0.1/'), '<epcis/>', 'shipment.xml');
    }

    #[Test]
    public function failure_message_is_status_only_without_response_body(): void
    {
        Http::fake([
            'https://8.8.8.8/as2' => Http::response('as2-secret-body', 502),
        ]);

        try {
            $this->sender()->send($this->connection('https://8.8.8.8/as2'), '<epcis/>', 'shipment.xml');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('AS2 outbound POST failed (HTTP 502).', $e->getMessage());
            $this->assertStringNotContainsString('as2-secret-body', $e->getMessage());
        }
    }
}

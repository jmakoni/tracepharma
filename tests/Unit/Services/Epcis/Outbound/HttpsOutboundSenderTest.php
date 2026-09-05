<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Services\Epcis\Outbound\HttpsOutboundSender;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HttpsOutboundSenderTest extends TestCase
{
    private const SAFE_ENDPOINT = 'https://8.8.8.8/epcis';

    #[Test]
    public function send_posts_xml_with_content_disposition_attachment(): void
    {
        Http::fake([
            self::SAFE_ENDPOINT => Http::response('ok', 200),
        ]);

        $connection = new OutboundConnection([
            'settings' => ['endpoint_url' => self::SAFE_ENDPOINT],
            'credentials' => ['webhook_token' => 'secret'],
        ]);

        app(HttpsOutboundSender::class)->send(
            $connection,
            '<epcis/>',
            '../unsafe/path/shipment.xml',
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === self::SAFE_ENDPOINT
                && $request->hasHeader('Content-Disposition', 'attachment; filename="shipment.xml"')
                && $request->hasHeader('X-Inbound-Token', 'secret')
                && $request->body() === '<epcis/>';
        });
    }

    #[Test]
    public function rejects_link_local_metadata_endpoint(): void
    {
        Http::fake();

        $connection = new OutboundConnection([
            'settings' => ['endpoint_url' => 'https://169.254.169.254/'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private or metadata host');

        app(HttpsOutboundSender::class)->send($connection, '<epcis/>', 'shipment.xml');
    }

    #[Test]
    public function rejects_private_rfc1918_endpoint(): void
    {
        Http::fake();

        $connection = new OutboundConnection([
            'settings' => ['endpoint_url' => 'https://10.0.0.1/'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('private or metadata host');

        app(HttpsOutboundSender::class)->send($connection, '<epcis/>', 'shipment.xml');
    }

    #[Test]
    public function does_not_follow_redirects(): void
    {
        Http::fake([
            self::SAFE_ENDPOINT => Http::response('', 302, [
                'Location' => 'https://169.254.169.254/secret',
            ]),
            'https://169.254.169.254/*' => Http::response('ssrf', 200),
        ]);

        $connection = new OutboundConnection([
            'settings' => ['endpoint_url' => self::SAFE_ENDPOINT],
        ]);

        try {
            app(HttpsOutboundSender::class)->send($connection, '<epcis/>', 'shipment.xml');
            $this->fail('Expected RuntimeException for non-success status without following redirect.');
        } catch (\RuntimeException $e) {
            $this->assertSame('HTTPS outbound POST failed (HTTP 302).', $e->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254.169.254'));
    }

    #[Test]
    public function failure_message_is_status_only_without_response_body(): void
    {
        Http::fake([
            self::SAFE_ENDPOINT => Http::response('secret-error-body-leak', 500),
        ]);

        $connection = new OutboundConnection([
            'settings' => ['endpoint_url' => self::SAFE_ENDPOINT],
        ]);

        try {
            app(HttpsOutboundSender::class)->send($connection, '<epcis/>', 'shipment.xml');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('HTTPS outbound POST failed (HTTP 500).', $e->getMessage());
            $this->assertStringNotContainsString('secret-error-body-leak', $e->getMessage());
        }
    }
}

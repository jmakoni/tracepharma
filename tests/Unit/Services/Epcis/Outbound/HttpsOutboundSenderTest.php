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
    #[Test]
    public function send_posts_xml_with_content_disposition_attachment(): void
    {
        Http::fake([
            'https://partner.example/epcis' => Http::response('ok', 200),
        ]);

        $connection = new OutboundConnection([
            'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            'credentials' => ['webhook_token' => 'secret'],
        ]);

        app(HttpsOutboundSender::class)->send(
            $connection,
            '<epcis/>',
            '../unsafe/path/shipment.xml',
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://partner.example/epcis'
                && $request->hasHeader('Content-Disposition', 'attachment; filename="shipment.xml"')
                && $request->hasHeader('X-Inbound-Token', 'secret')
                && $request->body() === '<epcis/>';
        });
    }
}

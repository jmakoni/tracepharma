<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Outbound;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;
use App\Services\Epcis\Outbound\SftpOutboundSender;
use DomainException;
use League\Flysystem\Filesystem;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SftpOutboundSenderTest extends TestCase
{
    #[Test]
    public function send_writes_content_to_outbound_path(): void
    {
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('write')
            ->once()
            ->with('outbound/epcis/ship-1.xml', '<epcis/>');

        $connection = new OutboundConnection([
            'transport' => OutboundTransport::Sftp,
            'settings' => [
                'host' => 'sftp.example.test',
                'outbound_path' => 'outbound/epcis',
                'root' => '/',
            ],
            'credentials' => [
                'username' => 'shipper',
                'password' => 'secret',
            ],
        ]);

        app(SftpOutboundSender::class)->send($connection, '<epcis/>', 'ship-1.xml', $filesystem);
    }

    #[Test]
    public function send_requires_host(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('host');

        app(SftpOutboundSender::class)->send(new OutboundConnection([
            'transport' => OutboundTransport::Sftp,
            'settings' => ['outbound_path' => 'outbound/epcis'],
            'credentials' => ['username' => 'shipper'],
        ]), '<x/>', 'a.xml');
    }

    #[Test]
    public function send_requires_username(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('username');

        app(SftpOutboundSender::class)->send(new OutboundConnection([
            'transport' => OutboundTransport::Sftp,
            'settings' => [
                'host' => 'sftp.example.test',
                'outbound_path' => 'outbound/epcis',
            ],
            'credentials' => [],
        ]), '<x/>', 'a.xml');
    }
}

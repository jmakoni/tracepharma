<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Listeners\SuppressNonDeliverableOutboundMail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SuppressNonDeliverableOutboundMailTest extends TestCase
{
    #[Test]
    public function it_does_not_send_to_reserved_test_domains(): void
    {
        $this->arrayTransport()->flush();

        Mail::to('owner@demo.test')->send(new TestOutboundMail);

        $this->assertCount(0, $this->arrayTransport()->messages());
    }

    #[Test]
    public function it_still_sends_to_deliverable_recipients(): void
    {
        $this->arrayTransport()->flush();

        Mail::to('ops@tracepharma.io')->send(new TestOutboundMail);

        $this->assertCount(1, $this->arrayTransport()->messages());
    }

    #[Test]
    public function it_strips_blocked_recipients_and_keeps_real_ones(): void
    {
        $email = (new Email)
            ->to('owner@demo.test', 'ops@tracepharma.io')
            ->subject('Mixed')
            ->text('mixed');

        $result = app(SuppressNonDeliverableOutboundMail::class)->handle(new MessageSending($email));

        $this->assertNull($result);
        $this->assertSame(['ops@tracepharma.io'], array_map(
            fn ($address) => $address->getAddress(),
            $email->getTo(),
        ));
    }

    #[Test]
    public function it_cancels_send_when_every_recipient_is_blocked(): void
    {
        $email = (new Email)
            ->to('owner@demo.test')
            ->cc('admin@tracepharma.test')
            ->subject('Blocked')
            ->text('no');

        $result = app(SuppressNonDeliverableOutboundMail::class)->handle(new MessageSending($email));

        $this->assertFalse($result);
        $this->assertSame([], $email->getTo());
        $this->assertSame([], $email->getCc());
    }

    private function arrayTransport(): ArrayTransport
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }
}

final class TestOutboundMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Test outbound');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>test</p>');
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Mail\NonDeliverableRecipient;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SuppressNonDeliverableOutboundMail
{
    public function handle(MessageSending $event): ?bool
    {
        if (! (bool) config('mail.block_non_deliverable_recipients', true)) {
            return null;
        }

        $message = $event->message;
        if (! $message instanceof Email) {
            return null;
        }

        $removed = [
            ...$this->filterList($message, 'to'),
            ...$this->filterList($message, 'cc'),
            ...$this->filterList($message, 'bcc'),
        ];

        if ($removed !== []) {
            Log::warning('Suppressed outbound mail to non-deliverable recipient domains.', [
                'removed' => $removed,
                'subject' => $message->getSubject(),
            ]);
        }

        if ($message->getTo() === [] && $message->getCc() === [] && $message->getBcc() === []) {
            return false;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function filterList(Email $message, string $kind): array
    {
        $getter = 'get'.ucfirst($kind);
        $current = $message->{$getter}();
        if (! is_array($current) || $current === []) {
            return [];
        }

        $kept = [];
        $removed = [];
        foreach ($current as $address) {
            if (! $address instanceof Address) {
                continue;
            }
            $email = $address->getAddress();
            if (NonDeliverableRecipient::blocks($email)) {
                $removed[] = $email;

                continue;
            }
            $kept[] = $address;
        }

        $message->{$kind}(...$kept);

        return $removed;
    }
}

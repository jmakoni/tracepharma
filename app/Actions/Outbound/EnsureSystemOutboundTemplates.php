<?php

declare(strict_types=1);

namespace App\Actions\Outbound;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;

final class EnsureSystemOutboundTemplates
{
    /**
     * Idempotently ensure inactive Email + Client portal system template rows exist.
     *
     * @return array{created: int, existing: int}
     */
    public function handle(): array
    {
        $created = 0;
        $existing = 0;

        foreach ($this->definitions() as $definition) {
            $row = OutboundConnection::query()
                ->where('system_key', $definition['system_key'])
                ->first();

            if ($row !== null) {
                $existing++;

                continue;
            }

            OutboundConnection::query()->create([
                'name' => $definition['name'],
                'serialization_provider' => SerializationProvider::Other,
                'transport' => $definition['transport'],
                'trading_partner_id' => null,
                'is_active' => false,
                'is_default' => false,
                'is_system' => true,
                'system_key' => $definition['system_key'],
                'credentials' => [],
                'settings' => $definition['settings'],
            ]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * @return list<array{system_key: string, name: string, transport: OutboundTransport, settings: array<string, mixed>}>
     */
    private function definitions(): array
    {
        return [
            [
                'system_key' => OutboundConnection::SYSTEM_KEY_EMAIL_ATTACHMENT,
                'name' => 'Email (EPCIS attachment)',
                'transport' => OutboundTransport::Email,
                'settings' => [
                    'to_emails' => [],
                    'cc_emails' => [],
                    'max_attachment_mb' => 15,
                    'notify_on_publish' => false,
                ],
            ],
            [
                'system_key' => OutboundConnection::SYSTEM_KEY_CLIENT_PORTAL,
                'name' => 'Client portal',
                'transport' => OutboundTransport::Portal,
                'settings' => [
                    'notify_on_publish' => true,
                    'invite_emails' => [],
                ],
            ],
        ];
    }
}

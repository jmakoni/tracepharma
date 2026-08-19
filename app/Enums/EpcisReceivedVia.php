<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an inbound EPCIS document entered the tenant.
 *
 * Inbound EPCIS catalog (Filament) only lists {@see self::catalogValues()}.
 */
enum EpcisReceivedVia: string
{
    case FilamentUpload = 'filament_upload';
    case HttpsWebhookHub = 'https_webhook_hub';
    case HttpsWebhook = 'https_webhook';
    case SftpPoll = 'sftp_poll';
    case Api = 'api';
    case Cli = 'cli';

    public function label(): string
    {
        return match ($this) {
            self::FilamentUpload => 'Upload EPCIS',
            self::HttpsWebhookHub => 'Inbound hub',
            self::HttpsWebhook => 'HTTPS webhook',
            self::SftpPoll => 'SFTP poll',
            self::Api => 'REST API',
            self::Cli => 'CLI / internal',
        };
    }

    /**
     * Channels shown on the Inbound EPCIS list (partner ingress).
     * CLI / internal untagged receives stay excluded.
     *
     * @return list<string>
     */
    public static function catalogValues(): array
    {
        return [
            self::FilamentUpload->value,
            self::HttpsWebhookHub->value,
            self::HttpsWebhook->value,
            self::SftpPoll->value,
            self::Api->value,
        ];
    }

    public function isCatalogVisible(): bool
    {
        return in_array($this->value, self::catalogValues(), true);
    }

    public static function tryFromNotes(?string $notes): ?self
    {
        $notes = trim((string) $notes);

        return match ($notes) {
            'Received via https_webhook_hub' => self::HttpsWebhookHub,
            'Received via https_webhook' => self::HttpsWebhook,
            'Received via sftp_poll' => self::SftpPoll,
            default => null,
        };
    }
}

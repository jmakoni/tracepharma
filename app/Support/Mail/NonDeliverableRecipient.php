<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Recipients that must never be handed to ZeptoMail / SMTP.
 *
 * Covers RFC 2606 / 6761 reserved names and common made-up test hosts
 * (*.test, example.com, *.localhost, *.local, *.invalid, *.example).
 * Does not block real domains such as test.com.
 */
final class NonDeliverableRecipient
{
    /**
     * Exact hosts and their subdomains (example.com, mail.example.com).
     *
     * @var list<string>
     */
    private const BLOCKED_DOMAINS = [
        'example.com',
        'example.net',
        'example.org',
    ];

    /**
     * Entire reserved / test TLDs.
     *
     * @var list<string>
     */
    private const BLOCKED_TLDS = [
        'test',
        'example',
        'invalid',
        'localhost',
        'local',
    ];

    public static function blocks(string $email): bool
    {
        $host = self::host($email);

        if ($host === '') {
            return true;
        }

        foreach (self::blockedDomains() as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        $tld = str_contains($host, '.')
            ? substr($host, (int) strrpos($host, '.') + 1)
            : $host;

        return in_array($tld, self::blockedTlds(), true);
    }

    public static function host(string $email): string
    {
        $email = trim($email);
        if (preg_match('/<([^>]+)>/', $email, $matches) === 1) {
            $email = $matches[1];
        }

        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return '';
        }

        return rtrim(substr($email, $at + 1), '.');
    }

    /**
     * @return list<string>
     */
    private static function blockedDomains(): array
    {
        $extra = config('mail.blocked_recipient_domains', []);

        return array_values(array_unique([
            ...self::BLOCKED_DOMAINS,
            ...array_map('strval', is_array($extra) ? $extra : []),
        ]));
    }

    /**
     * @return list<string>
     */
    private static function blockedTlds(): array
    {
        $extra = config('mail.blocked_recipient_tlds', []);

        return array_values(array_unique([
            ...self::BLOCKED_TLDS,
            ...array_map('strval', is_array($extra) ? $extra : []),
        ]));
    }
}

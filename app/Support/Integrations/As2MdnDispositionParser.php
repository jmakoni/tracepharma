<?php

declare(strict_types=1);

namespace App\Support\Integrations;

final class As2MdnDispositionParser
{
    /**
     * Resolve transmission MDN status from an AS2 disposition-notification body.
     *
     * @return 'received'|'failed'|null
     */
    public function mdnStatusFromBody(string $body): ?string
    {
        $disposition = $this->extractDisposition($body);

        if ($disposition === null) {
            return null;
        }

        return $this->mdnStatusFromDisposition($disposition);
    }

    public function extractDisposition(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        if (preg_match('/^Disposition:\s*(.+)$/mi', $body, $matches) !== 1) {
            return null;
        }

        $disposition = trim($matches[1]);

        return $disposition !== '' ? $disposition : null;
    }

    /**
     * @return 'received'|'failed'
     */
    public function mdnStatusFromDisposition(string $disposition): string
    {
        $normalized = strtolower($disposition);

        if (str_contains($normalized, 'failed')
            || str_contains($normalized, '/error')
            || str_contains($normalized, '; error')) {
            return 'failed';
        }

        if (str_contains($normalized, 'processed') || str_contains($normalized, 'deleted')) {
            return 'received';
        }

        return 'failed';
    }
}

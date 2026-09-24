<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Filament\App\Resources\Exceptions\ExceptionResource;
use App\Models\Receiving\ReceivingSession;

/**
 * Deep-link into Exceptions All Open filtered by receive session (and optional type).
 */
final class ReceiveSessionExceptionInbox
{
    /**
     * @param  list<string>  $typeCodes
     */
    public static function url(ReceivingSession $session, array $typeCodes = []): ?string
    {
        if (! ExceptionResource::canAccess()) {
            return null;
        }

        $filters = [
            'receiving_session_id' => ['value' => (string) $session->getKey()],
        ];

        if ($typeCodes !== []) {
            $filters['type_code'] = ['value' => implode(',', $typeCodes)];
        }

        return ExceptionResource::getUrl('index', [
            'tab' => 'all_open',
            'filters' => $filters,
        ], panel: 'app');
    }

    /**
     * @return array<string, string|null>
     */
    public static function badgeUrls(ReceivingSession $session): array
    {
        $urls = [];

        foreach (ReceiveSessionExceptionQuery::badgeTypeCodes() as $key => $codes) {
            $urls[$key] = self::url($session, $codes);
        }

        return $urls;
    }
}

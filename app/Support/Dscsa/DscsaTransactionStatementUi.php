<?php

declare(strict_types=1);

namespace App\Support\Dscsa;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\EpcisDocument;

/**
 * Whether the EPCIS document UI should show Transaction Statement / Legal notice.
 *
 * DSCSA TS applies to ownership-change partner shipping (inbound ASN or outbound ship).
 * Internal transfer, generated receiving, commissioning, etc. are not DSCSA transactions —
 * showing “affirmed: No” there is misleading.
 */
final class DscsaTransactionStatementUi
{
    public static function applies(?EpcisDocument $document): bool
    {
        if ($document === null) {
            return false;
        }

        $kind = self::resolvedAuthoredKind($document);

        if ($kind instanceof EpcisAuthoredKind) {
            return $kind === EpcisAuthoredKind::Shipping;
        }

        $direction = (string) ($document->direction ?? '');

        return in_array($direction, ['inbound', 'outbound'], true);
    }

    private static function resolvedAuthoredKind(EpcisDocument $document): ?EpcisAuthoredKind
    {
        $kind = $document->authored_kind;
        if ($kind instanceof EpcisAuthoredKind) {
            return $kind;
        }

        if ((string) ($document->direction ?? '') !== 'outbound') {
            return null;
        }

        return EpcisAuthoredKind::inferAuthoredKindFromNotesAndFilename(
            (string) $document->notes,
            (string) $document->original_filename,
        );
    }
}

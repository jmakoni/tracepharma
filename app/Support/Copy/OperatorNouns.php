<?php

declare(strict_types=1);

namespace App\Support\Copy;

/**
 * Canonical operator-facing nouns. Bound-record copy must use these
 * so session / ASN / file / ship order / transfer / exception are never conflated.
 */
final class OperatorNouns
{
    public const SESSION = 'session';

    public const RECEIVE_SESSION = 'receive session';

    public const ASN = 'ASN';

    public const INBOUND_FILE = 'inbound file';

    public const SHIP_ORDER = 'ship order';

    public const TRANSFER = 'transfer';

    public const EXCEPTION = 'exception';

    public const HOLD = 'hold';

    /** Floor overlay when ASN/file warehouse receive is finished. */
    public const FLOOR_RECEIVED = 'Received';

    /** Floor overlay when some (not all) expected ASN lines are confirmed. */
    public const FLOOR_PARTIALLY_RECEIVED = 'Partially Received';

    public const FLOOR_RECEIVE_BLOCKED = 'Receive Blocked';

    public const COMPLETE_SESSION = 'Complete session';

    public const ASN_PARTIAL = 'ASN partial';

    public const ASN_COMPLETE = 'ASN complete';

    public const ASN_RECEIVE_BADGE = 'ASN receive';

    public const SHIP_ORDER_SENT = 'Ship order sent';

    public const SESSION_SHIPPED = 'Session shipped';

    public const SHIP_ORDER_AUTHORED = 'Ship order authored';

    public const SHIP_ORDER_CLOSED = 'Ship order closed';

    public const SHIP_ORDER_INCOMPLETE = 'Ship order incomplete';

    private function __construct() {}
}

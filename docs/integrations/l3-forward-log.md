# L3 forward log

Manufacturer operators can open **Compliance → L3 forward log** to see commissioning EPCIS documents that were forwarded to the external L3 endpoint (`l3_forwarded_at`) or that have an open `L3_TRANSMISSION_FAILURE` exception.

**Retry** re-dispatches `ForwardCommissioningToL3` when the document has payload content and Organization L3 is enabled (endpoint set).

## Honesty

This page is **forward status only**. It is not SGTIN allocation, Guardian lot ingest, or L2↔L3 count reconciliation. There is no public allocation API.

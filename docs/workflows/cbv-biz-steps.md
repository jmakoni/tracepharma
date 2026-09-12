# CBV biz steps & dispositions (authored)

Oracle for TracePharma **authored** (outbound / disposition / hierarchy) events. Inbound partner files may use additional allowlisted steps; see [`EpcisCbvAllowlist`](../../app/Support/Epcis/Validation/EpcisCbvAllowlist.php).

Canonical form is the full URN (`urn:epcglobal:cbv:bizstep:…` / `urn:epcglobal:cbv:disp:…`). Storage may keep either URN or local name depending on the writer path.

## Workflow → authored values

| Workflow action | Event type | biz_step | Typical disposition | Primary action |
|---|---|---|---|---|
| Receive complete | ObjectEvent | `receiving` | `in_progress` | `GenerateReceivingEpcisEvents` |
| Unpack | AggregationEvent DELETE | `unpacking` | `in_progress` | `UnpackReceivingHierarchy` / receive unpack branch |
| Pack / SSCC aggregate | AggregationEvent ADD | `packing` | `in_progress` | `GenerateSsccAggregationEvent` / pack emitters |
| Commission | ObjectEvent | `commissioning` | `active` | `EmitCommissioningEpcisForEpcs` → `GenerateDispositionObjectEvent` |
| Outbound ship / Scan Out | ObjectEvent | `shipping` | `in_transit` | `GenerateShippingEpcisEvents` |
| Transfer ship | ObjectEvent | `shipping` | `in_transit` | `GenerateTransferringEpcisEvents` |
| Transfer receive | ObjectEvent (often + ship in one doc) | `receiving` | `in_progress` | `GenerateTransferringReceiveEpcisEvents` |
| Return / saleable return | ObjectEvent | `returning` | `returned` | `EmitReturningEpcis` |
| Decommission | ObjectEvent | `decommissioning` | reason-mapped (see below) | `EmitDecommissioningEpcis` |
| Repack transform | TransformationEvent | `commissioning` | output `active` | `AuthorTransformationRepack` |

## Decommission reason → disposition

`biz_step` stays **`decommissioning`**. Only disposition changes (`DecommissionReason`):

| UI reason | Disposition URN suffix |
|---|---|
| Destroyed | `destroyed` |
| Expired | `expired` |
| Recalled | `recalled` |
| Returned | `returned` |
| Inactive | `inactive` |
| Disposed | `disposed` |

## Quirks (do not “fix” in docs without product decision)

1. **Transfers** do not invent a `transferring` biz_step — they reuse trading-partner **`shipping` / `receiving`**.
2. **Repack transform** labels TransformationEvent with **`commissioning`**, not `repackaging`.
3. Do not confuse **disposition** (lifecycle state) with **biz_step** (process step), especially on decommission.
4. Outbound **shipping** events should carry DSCSA TI/TS document fields when affirm is on.

## Allowlist (inbound validation)

Allowed biz steps and dispositions for hard-gate / allowlist validation live in `EpcisCbvAllowlist::BIZ_STEPS` and `::DISPOSITIONS`.

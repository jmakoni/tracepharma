# GS1 / DSCSA standards-probe fixes

Report of production files and covering tests for each probe ID. Keep-table items were not changed.

| ID | Production | Test |
| --- | --- | --- |
| **P0-1** receiveAllExpected | `app/Filament/App/Resources/ReceivingSessions/Concerns/InteractsWithReceivingSessionHud.php` — HUD now calls `ConfirmRemainingExpectedReceivingLines` (scanned parents + inbound children; unscanned parents → `ensureShortageFromShortClose`; no Complete without that exception). | `tests/Feature/Receiving/ReceiveModeAcceptRemainingTest.php` — `receive_all_expected_files_shortage_and_does_not_uri_confirm_unscanned_parents` |
| **P0-2** inbound packing scope | `app/Support/Receiving/ResolveInboundAggregationChildEpcs.php` (`parentEpcIdsForChild`); `app/Actions/Receiving/UnpackReceivingHierarchy.php` (`authorEventsOnDocument`); `app/Actions/Receiving/ConfirmReceivingScan.php` (outer parent / case cover); `app/Support/Receiving/ReceivingPackShape.php`; `app/Support/Receiving/ReceivingPolicy.php`; `app/Actions/Receiving/StageReceivingScan.php`. | `tests/Feature/Receiving/GenerateReceivingEpcisEventsTest.php` — `unpack_delete_closes_only_inbound_aggregation_children` |
| **P0-3** owning_party | `GenerateShippingEpcisEvents::owningPartySgln` uses recorded facility/org SGLN + `Sgln::toFacilityUrn` (fail closed). Packed-SSCC full-history path (`BuildFullHistoryShippingEpcisXml::resolveSourceOwningParty`) collapses source owning to facility URN the same way; `sdt:location` may still carry dock. | `tests/Feature/Shipping/OutboundShippingSessionTest.php` — `partner_ship_owning_party_is_facility_sgln_not_dock_extension` |
| **P1-A** SBDH Sender | Lean path: `GenerateShippingEpcisEvents::resolveAuthoredPartyFields` Sender = tenant org GLN only (fail closed, no `site.gln` fallback); `createAuthoredDocument` persists `$tiTs`. Packed-SSCC full-history: `BuildFullHistoryShippingEpcisXml::resolveSbdhSenderGln` (org GLN; principal GLN only on agent TI). | `tests/Feature/Shipping/OutboundShippingSessionTest.php` — `partner_ship_sbdh_sender_is_tenant_org_gln_not_site_gln` |
| **P1-B** timezone leftovers | `GenerateSsccCommissioningEvent`, `GenerateSsccDisaggregationEvent`, `BuildFullHistoryShippingEpcisXml` (object/aggregation/shipping), `AuthorTransformationRepack::buildXml` — `AuthoredEventTimezone::offsetForSite` (site of bizLocation). | `tests/Unit/Actions/Outbound/GenerateSsccCommissioningEventTest.php`, `GenerateSsccDisaggregationEventTest.php`; `tests/Feature/Packing/AuthorTransformationRepackTest.php`; owning-party ship XML also asserts site offset; `tests/Unit/Support/Gs1/IdentifierEntryStandardsTest.php` leftover source check |
| **P1-C** read-point lookup | `app/Actions/Epcis/ResolveGlnToMasterData.php` — `findReadPointForGln` matches stored GLN digits or stored SGLN URN only; prefix-length walk deleted. | `tests/Feature/Epcis/ResolveGlnToMasterDataTest.php` — `it_matches_a_read_point_stored_as_gln_digits_without_prefix_walk` (plus existing SGLN URN resolve) |
| **P2** identifiers | `Gs1LocationNormalizer`, `Catalog\Gln::normalize` → `ValidGln`; `SbdhHeaderExtractor` via normalizer; `VerifyProduct`, `DispenseCheckController`, `RespondToInboundVerification` → `Gtin::fromUpc`, no serial/lot trim; `SupplierQuarantineTableBuilder`, Exceptions `EpcsRelationManager` use stored `gtin14` only. | `tests/Unit/Catalog/GlnTest.php`; `tests/Unit/Support/Gs1LocationNormalizerSglnTest.php`; `tests/Unit/Support/Gs1/IdentifierEntryStandardsTest.php` |

## Not in this PR

- Keep table: OBSERVE, lean ship omit bizLocation, dock `in_progress`, PDG child inference, `SglnResolution` from stored GCP, lot-level refuse on serialized/transfer.
- `allowAssignPartnerGlnsFromPrefix` left off.
- No `Seed*` changes.
- Floor / On-hand UX unchanged.
- OBSERVE not flipped to ADD.

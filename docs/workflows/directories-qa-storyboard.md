# Operations Hub Directories QA storyboard

Ordered watch-through of screenshots under [media/directories-qa/](media/directories-qa/). Tenant: demo2 (`owner@demo.test`). Hub: `/operations-hub`.

Related: [shell-and-site.md](shell-and-site.md) (Directories inventory), [verify-product.md](verify-product.md).

## 00 — Hub

1. `00-operations-hub-full.png` — full Operations Hub after login / site context.
2. `00-operations-hub-directories.png` — Directories section (19 visible cards).

## 01 — Receive (`/receiving-sessions`)

3. `01-receive-landing.png`
4. `01-receive-list.png`
5. `01-receive-search.png`

## 02 — Unpacking (`/unpack-workstation`)

6. `02-unpack-landing.png`
7. `02-unpack-deep.png`

## 03 — Unpacked items (`/unpacked-items`)

8. `03-unpacked-items-landing.png`
9. `03-unpacked-deep.png`

## 04 — Packing (`/pack-workstation`)

10. `04-pack-landing.png`
11. `04-pack-deep.png`

## 05 — Break & pack (`/break-pack-workstation`)

12. `05-break-pack-landing.png`
13. `05-break-pack-deep.png`

## 06 — Return (`/return-workstation`)

14. `06-return-landing.png`
15. `06-return-deep.png`

## 07 — Transfer (`/transferring-sessions`)

16. `07-transfer-landing.png`
17. `07-transfer-deep.png`
18. `07-transfer-list.png`

## 08 — Asset Tracking (`/asset-tracking`)

19. `08-asset-tracking-landing.png`
20. `08-asset-deep.png`

## 09 — Verify product (`/verify-product`)

21. `09-verify-landing.png` — page title **Dispense / verify**; Hub card label **Verify product**.
22. `09-verify-deep.png`

## 10 — Integration health (`/integration-health`)

23. `10-integration-health-landing.png`
24. `10-integ-deep.png`

## 11 — Analytics (`/analytics`)

25. `11-analytics-landing.png`
26. `11-analytics-deep.png`

## 12 — Inbound EPCIS (`/inbound-epcis`)

27. `12-inbound-epcis-landing.png`
28. `12-inbound-epcis-deep.png`

## 13 — Inbound Connections (`/inbound-connections`)

29. `13-inbound-connections-landing.png`
30. `13-inbound-conn-deep.png`
31. `13-inbound-conn-view.png`

## 14 — API Tokens (`/api-tokens`)

32. `14-api-tokens-landing.png`
33. `14-api-tokens-deep.png`

## 15 — Find / Recall (`/inbound-epcis?action=findRecall`)

34. `15-find-recall-landing.png` — legacy `?findRecall=1` alone did not open the modal on deployed demo2 before the Hub URL fix.
35. `15-find-recall-action-param.png` — working deep link with `?action=findRecall`.
36. `15-find-recall-search.png` — ASN `QA-CURSOR-NO-MATCH` → **No matches** notification.
37. `15-find-recall-from-hub.png` / `15-find-recall-retry.png` / `15-find-recall-modal.png` / `15-find-recall-header-*.png` — alternate entry diagnostics.

## 16 — Trading Partners (`/trading-partners`)

38. `16-trading-partners-landing.png`
39. `16-trading-partners-search.png`
40. `16-trading-partners-view.png` — partner-first; do not overwrite demo partners.

## 17 — FDA Products (`/fda-products`)

41. `17-fda-products-landing.png`
42. `17-fda-deep.png`
43. `17-fda-search.png`

## 18 — Product directory (`/products`)

44. `18-products-landing.png`
45. `18-products-deep.png`
46. `18-products-search.png`

## 19 — Site directory (`/sites`)

47. `19-sites-landing.png`
48. `19-sites-search.png` / `19-sites-search-qa.png`
49. `19-sites-create-form.png` — Name + Code required.
50. `19-sites-validation.png` / `19-sites-empty-confirm-before-fix.png` — empty Name → required validation.
51. `19-sites-create-success.png` — **QA Cursor Demo Site** / `QA-CUR-SITE-1` after regulatory **Confirm create**.

## Gated (not in storyboard)

Commission-all, Decommission, Ship Order, Outbound EPCIS — absent on demo2 by feature gates.

# Existing tax country and state — RC130

Saved rate cards now open a Country & state task beside calculation and city/postcode tasks. Two tiles show saved coverage and the proposed region. The country and state save together through native `WC_Tax::_update_tax_rate`, updating only those two columns. Changing the draft country explicitly clears its state after confirmation; saved city/postcode restrictions are preserved, not translated or silently deleted. Confirmations explain all-country/all-state matching, remaining local restrictions and unchanged existing order totals. No tax obligation or rate is chosen for the client.

Existing native rate values, class and all bounded location rows/IDs are read back unchanged. The existing nonwaiting tax lock, expected-state snapshot, InnoDB transaction guard and cache invalidation are reused. Invalid state/country combinations, unknown codes, injected fields, stale requests and insufficient roles are denied. Failed writes/readback/commit roll back. This adds no public hooks, schema work, visitor logging or new option store. Other plugins do not share Kiwe's lock; transaction/readback does not certify arbitrary concurrent writers or external hook side effects.

Native country/state catalogs come from WooCommerce. Custom saved country/state or rate values and more than 200 locations remain protected. Catalogs are capped at 300 choices and oversized catalogs cannot be edited. Empty country/state explicitly represent native wildcards. Existing custom regions are visible for review. Mutation needs product-editing plus Kiwe context capability; managers can review and editorial roles cannot enter. Exact private GET/POST geography endpoints use the existing nonce/origin/mutation-proof guards.

The React task preserves drafts on uncertain saves and blocks retry until refresh, warns before discarding unsaved work, and refreshes state choices when country changes. New controls remain in the existing tax workspace, without thumbnails or additional page-wide runtime dependencies. Country/state changes do not edit tax classes, products, city/postcode rules or customer orders.

## Verification and deployment

216 combined focused PHP checks passed, including 30 geography checks. Package manifest verification passed with 417 files. The browser harness initially stopped on an incorrect Back-button selector in the newly added test; corrected selector and all 71 browser checks passed, with phone/desktop screenshots reviewed. All 82 release gates passed across the original 80 passes and the two corrected gates: changelog readiness and generated catalog version freshness. Native WordPress acceptance passed as recorded below. Local and lab RC130. Ascendants remains recorded RC55.4 and is untouched. No commit, push or signing.

Native acceptance extends the isolated class/rate fixture to check native matching after state, country and wildcard changes, preservation, stale requests, failed native writes and roles. Synthetic outbound work is blocked; cleanup and settings preservation require explicit parsed receipts. External evidence belongs in `work/rc130-*` outside the repository.

## Continuation

Backend first. Tax class/deletion lifecycle and product classification keep the broader tax work open after geography acceptance. The accepted RC130 forecast is 11–18 backend and 19–32 overall batches; update it from accepted backlog changes, not simply an RC increment. Broad client-area percentages remain evidence classifications, not percentage of implementation completed. Existing Product Vision, Design Context and maintainer ownership contracts are unaffected.

## Accepted lab release

Installed at 2026-09-19T09:43:01+00:00. All 430 native checks passed, including 20 geography assertions: native matching changes with state/country/wildcard choices, other native columns and every saved location ID remain unchanged, stale/malformed/role requests are rejected and refused native updates preserve the existing scope. Explicit receipt flags: passed=true, cleanup=true, settingsPreserved=true. Fixture records and original options were cleaned/restored. Native tests and synthetic browser acceptance do not certify every provider or authenticated human-browser checkout.

Immutable artifact: 420 ZIP entries, 417 manifest files, 1,740,963 bytes, SHA256 b788d768ef5e1dbc98d7c228bdbf6f81fb0e6d8f11d8cc6550a91609d5a86dd5. Runtime Commerce JS 135,957 bytes / 32,328 gzip; CSS 5,970 / 1,995 gzip. Existing WordPress React is reused. No runtime records or sample fixtures are bundled.

Final receipt confirms debug off, previous RC129 retained outside the public tree, RC130 helpers retired, published sample 2938 with featured/inline attachment 2325 and category 129 intact. Temporary deployment/retirement jobs removed, final cron inventory empty. Fresh public homepage HTTP 200 HTML with expected Dharwad branding and no database/critical-error marker. Ascendants unchanged. No commit/push/signing. Cumulative main baseline 731a94ff48dd350bb7b168ec285fcc96afbdaa01; unrelated work preserved.

External receipts: work/rc130-tax.txt, rc130-baseline.txt, rc130-foundation.txt, rc130-rc12.txt, rc130-browser/receipt.json and geography-phone/desktop.png, rc130-deploy.json, rc130-native-acceptance.json, rc130-final.json and rc130-public.json. The full baseline initially passed 80 gates; the generated version catalog and changelog readiness were corrected and their two gates rerun successfully. Browser test selector was corrected before its 71-check pass; no runtime relaxation was used.

Handoffs, architecture, commerce inventory, coverage and progress updated. Remaining backend 11–18 and overall 19–32 stay unchanged because class/deletion lifecycle and product classification still keep the broader tax backlog item open. No added scope or claimed whole-area completion percentage. Next build continues those backend workflows before DSA.

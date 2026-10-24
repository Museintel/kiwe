# Apps migration: compatibility stop and recovered production state

25 October 2026, AP125. The owner approved the exact [Core436 / Store1.0.0 / SEO1.0.0 review](APPS-RC436-LIVE-REVIEW-2026-10-25.md). The production migration is **not complete**. Both stores finish on their original signed bundled RC427 with Store and SEO enabled. Ascendants and the public Core update feeds are unchanged.

| Site | Final Kiwe | Existing WooCommerce | Bricks | Independent Apps |
| --- | --- | --- | --- | --- |
| Nationalchikki.com | RC427, exact original code recovered | 10.4.4 | 2.3.4 | Not installed |
| Dharwadpedha.com | RC427, no runtime deployment attempted | 11.0.1 | 2.3.11 | Not installed |

The signed Store1.0.0 release requires WooCommerce11.2 or later. This actual-site prerequisite was missed in the original rollout review. The native installer refused National's older WooCommerce before creating an install job or placing an App. The refusal was respected; neither the requirement nor the signed immutable release was changed. Updating the commerce engine and its database was outside the approved Kiwe-only migration that preserved the existing Woo version.

## What happened and what was preserved

Fresh native baselines capture the exact signed RC427 code,159 authored documents,527 native controls,906 role-assignment rows and436 term relationships. Original order data is also captured by keyed hashes across native/legacy order, item, item-meta, address, operational and order-meta tables. National has257 native orders and257 legacy order/refund records; Dharwad has111 native orders and122 legacy order/refund records. These are table inventories, not a deduplicated sales count.

National's bundled RC434 bridge passes682 installation and seven fresh-boot checks. The first follow-up comparison wrongly rejected gaps in the original `active_plugins` array indexes. It is corrected to compare the values in their original relative order; the stored plugin selection/order was already unchanged. The original failed receipt and inputs are retained. The corrected native proof passes19,443 checks.

The subsequent Store review refuses the existing Woo10.4.4 dependency. No separate Store/SEO plugin is placed or activated. National is returned to the original authenticated RC427 ZIP through the existing signed native code-update owner. Recovery passes658 installation/seven boot checks;19,441 native checks preserve the original documents, metadata, controls, prices, weights, stock, order rows, configuration, roles, raw App choices and automatic-update choices. No customer/order database is restored to reverse code.

Dharwad receives only its read-only preservation capture and the isolated guest verification below. Its Kiwe/Woo/theme/plugin versions are untouched. Both sites retain their original online active-release RC421 recovery from the earlier accepted RC427 rollout.

The corrected reusable preflight now checks the actual Woo version against the signed Store packet's `requiresWoo` **before any compatibility bridge can be admitted**. A future attempt needs fresh operation names, fresh captures and an accepted prerequisite; the old passed code-only preflight must not be replayed as dependency approval.

## Public verification and limits

Both sites return the expected RC427 on Home, Shop and a real product page. The served JavaScript and CSS match the signed original bytes. Sitemap aliases and robots respond correctly. Real isolated anonymous API sessions successfully add one product, retain it, obtain the guest checkout fields without a login requirement, open the configured native checkout page and finish with an empty cart. No order, payment, campaign or test email is submitted.

The initial National probe's removal by the returned item key received404. Its failure receipt is retained. The final probes use the existing native product-ID item lookup and explicitly verify the empty cart. The first session was not serialized and cannot be revisited; retirement of that unused ephemeral Woo session is not claimed. No ambiguous customer session was deleted.

Browser use is disabled in Work settings during this turn. These are native/public HTTP proofs; a fresh browser rendering, signed-in purchase interaction or final screenshot is not claimed. Source/Core qualification from AP124 remains separate from live migration acceptance.

## Recovery custody and temporary cleanup

All1,210 original failed-run files are independently verified, including ZIP member count, every byte count and SHA256, in a DPAPI-protected local archive. Archive bytes6,990,425; SHA256`d303d0a4bfa7eb770346de34284adbacf3c524e3f3294654dc1b29335d08c452`. The original failure receipts are inside this archive.

After accepted recovery, offline readback and exact unchanged-tree guards, compaction passes3,869 checks and retires1,210 files/151 directories,26,695,099 raw bytes. The duplicate transfer archive is separately retired and not counted again as original savings. Current signed RC427 and the original immediate active-release RC421 recovery are verified before removal. Compact closure receipts remain private; all task helpers/upload archives are retired. Independent Hostinger inventory retains only original cron`OpIYMKCWph`. Six retired credential/configuration/session inputs have verified DPAPI custody; the upload credentials were already protected. No release key is changed.

## Reviewed prerequisite prepared after the stop

The existing lab already runs physical Core436, Store1.0.0, SEO1.0.0 and Woo11.2.1. Two new isolated actual WordPress7.1.3/MariaDB11.8.3/PHP8.4 cases now exercise **Woo10.4.4 →11.2.1** and **Woo11.0.1 →11.2.1**, followed by the signed RC434 bridge, native resumable stable App installation, fresh acceptance and physical Core436.

Both cases pass94 named checks across17 fresh native requests each. National's path executes25 native Woo database callbacks; Dharwad's executes13. Synthetic products, pack weights, stock, order totals/statuses/addresses/items and captured merchant choices remain exact. Final guest Add/Checkout/removal passes and all45 optional engine files are absent from Core. These are synthetic upgrade proofs, not cloned production databases, paid gateway tests or a hosting-concurrency benchmark. Linux lab qualification and the previously recorded Bricks-version checks remain separately scoped.

The three official Woo archives match every packaged member against WordPress.org checksums:5,629 members for10.4.4;5,872 for11.0.1;5,488 for11.2.1. The proposed official11.2.1 ZIP is18,060,641 bytes, SHA256`5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586`.

All110 isolated test tables are removed after qualification, unrelated local table inventory remains exact, and the owned database process/port is stopped. The new fixture database configuration and test session inputs are protected. [The concrete prerequisite review](WOO-APPS-PREREQUISITE-REVIEW-2026-10-25.md) owns the additional live approval; the original Kiwe artifact approval remains recorded.

Original work evidence: `work/ap125/authorization.json`, `offline-accepted.json`, `compact-and-crons.json`, `public-nationalchikki.com-first.json`, both final `public-<domain>.json`, `official-woo-accepted.json`, `woo-migration-result.json`, `woo-fixture/database-cleanup.json` and `protected-inputs.json` in the operator workspace. No runtime package, App release signature, shared Start/Seam release or public Core feed is modified by this run.

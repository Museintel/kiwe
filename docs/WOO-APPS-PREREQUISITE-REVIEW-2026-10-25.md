# WooCommerce prerequisite for the approved Apps migration

**Execution accepted,25 October — AP126:** both named approvals are granted and both stores now run Woo11.2.1/Core436/Store1.0.0/SEO1.0.0. [Actual execution, recovery and limitations](APPS-RC436-LIVE-2026-10-25.md). The following prepared review/earlier stop text is retained as historical authorization evidence; artifact identities remain exact.

Prepared25 October2026 after the [compatibility stop and recovery](APPS-RC436-LIVE-ATTEMPT-2026-10-25.md). **Awaiting additional named approval for the WooCommerce engine/database change.** The original Core436/Store1.0.0/SEO1.0.0 approval is retained.

| Target | Current WooCommerce | Proposed WooCommerce | Kiwe migration after prerequisite |
| --- | --- | --- | --- |
| Nationalchikki.com | 10.4.4 | 11.2.1 | Signed bridge434 → Store1.0.0 + SEO1.0.0 → physical Core436 |
| Dharwadpedha.com | 11.0.1 | 11.2.1 | Same reviewed immutable artifacts |

Official package: `https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip`;18,060,641 bytes; SHA256`5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586`. All5,488 packaged members match WordPress.org checksums. The signed Store App's minimum is11.2. The existing lab already uses11.2.1 with the reviewed physical Core and stable Apps.

The two actual older-version upgrade paths are rehearsed in isolated WordPress7.1.3/MariaDB11.8.3/PHP8.4 fixtures:94 checks and17 fresh requests per target. Native Woo database callbacks complete, original synthetic business records and merchant choices remain, the complete native App installation/physical cutover passes, and guest cart/checkout works. All110 synthetic tables are retired and the local database is stopped. This is not a full production-database clone or a paid gateway transaction test; no fresh final browser screenshot is available while browser use is disabled.

Live execution will capture fresh target code, database recovery and native choices; qualify capacity and incoming checkout/order activity; and update one store at a time through native Woo/WordPress owners. The prerequisite check must run before the bridge. Pause checkout entry during the short commerce update/migration window while preserving required payment callbacks. Inspect obsolete experimental Woo cart-recovery/unsubscribe data before the documented11.2 cleanup; stop if it needs a separate data/consent preservation plan. Run the required native database update and check completion before resuming the already-approved App migration. Preserve existing pages/templates, products, prices, merchant configuration, accounts, stock and original automatic-update choices. Theme and other-plugin versions retain their existing authority.

After each stage, accept fresh native boots and original-business-data comparisons, verify the actual guest/cart/checkout endpoint and source bytes, then retire exact task helpers/jobs and superseded recovery copies. No paid live order or campaign is included. Recovery must account for completed Woo schema changes; do not pair old code with an incompatible migrated database or restore older customer/order state over newer arrivals. Reconcile the captured original data and current activity before any data recovery.

The native update includes database changes because these stores predate11.2. Woo's [11.2 pre-release documentation](https://developer.woocommerce.com/2026/09/21/woocommerce-11-2-0-pre-release-notes/) identifies the migrations and removal of experimental recovery APIs/tables. [11.2.1 release notes](https://developer.woocommerce.com/2026/10/09/woocommerce-11-2-1-release-notes/) describe the classic-checkout/address and postcode fixes over11.2.0. Woo's [update guidance](https://woocommerce.com/document/how-to-update-woocommerce/) calls for current recovery, staging checks, required database updates and post-update store verification.

Hostinger also reports existing issues in older Razorpay/invoice-plugin versions. Their separate updates are not silently included in this artifact set, and these compatibility checks do not constitute a complete security clearance of every installed extension. Ascendants, external SEO migration, Google/MCP work and deferred CSS/shared-host performance work retain their separate scope.

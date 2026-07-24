# DSA Operations

## Canonical Source

The only deployment source is:

```text
C:\Users\munaf\Documents\dsa-dual-surface\wp-content\mu-plugins
```

Upload both `dsa.php` and the complete `dsa/` directory. Do not mix files from historical copies.

## Emergency Disable

MU plugins cannot be disabled from the normal Plugins screen. Rename:

```text
wp-content/mu-plugins/dsa.php
```

to:

```text
wp-content/mu-plugins/dsa.disabled.php
```

The nested package may remain in place. Restore the loader name only after the failing deployment has been corrected.

## Recovery Order

1. Disable the root loader if WordPress cannot boot.
2. Read `wp-content/debug.log` and identify the first Kiwe-owned fatal, not later cascading notices.
3. Restore the last known complete `dsa.php` plus `dsa/` pair; never mix versions.
4. Confirm wp-admin, REST, Bricks editor, public pages, PhoneKey, and Woo checkout.
5. Re-enable SecureTrack enforcement or controlled pilots only after their recovery checks pass.

SecureTrack also has fail-open boot handling, emergency mode, and private break-glass recovery. Those mechanisms supplement the loader rename; they do not replace a tested rollback copy.

## Stored State

Kiwe stores more than the original scaffold options. State includes `dsa_settings`, shell/registry/build manifests, diagnostics/profiler settings, PhoneKey and SecureTrack tables/options, Analytics/notification/push data, Woo product/order metadata, user preferences, and saved items. Exact storage evolves by version.

Do not delete options or tables manually as a routine rollback. A code rollback must preserve migration compatibility. Destructive removal requires a separately reviewed uninstall/migration procedure.

## Production Quietness

- Keep frontend debug and console logging off.
- Keep runtime profiling off except during a bounded evidence collection.
- Keep S15 morphing and S17 offline editorial off until certified.
- Start SecureTrack in monitor mode and CSP in report-only mode.
- Review cron health for Push, analytics maintenance, SecureTrack, and bestseller/co-purchase jobs.
- Treat `wp_mail()` success as transport handoff only; monitor actual inbox delivery.

## Bricks Safety

The Surface renderer skips Bricks builder requests. Kiwe Bricks integration is additive: it may expose controls, tags, tokens, palettes, and attributes, but page design remains Bricks-owned. If the builder is affected, disable Kiwe through the loader, capture the request URL/flags and debug log, and restore only a complete corrected package.

## Evidence Before Release

- PR1 Commerce matrix
- PR2 PhoneKey matrix
- PR3 SecureTrack/Push matrix
- PR4 runtime/cache/device matrix
- S16 editorial lifecycle proof if morphing will be enabled
- S17 offline proof if offline editorial will be enabled
- Hostinger upload, rollback, and smoke checklist

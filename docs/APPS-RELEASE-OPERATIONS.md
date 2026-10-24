# Independent App release operations

Core and normal App releases use the existing offline Ed25519 custodian. Never upload that private key to a WordPress site, public origin, helper or source repository. Publish immutable ZIP and release metadata first, verify their served bytes, then atomically replace the channel catalogue. Store and SEO are independently versioned; a one-App release retains the other authenticated release and all revocations.

New public release directories need explicit public traversal permissions after creation under a private operator umask. Verify all immutable release files through the actual HTTPS origin before offering their catalogue. A successful filesystem write alone is not distribution acceptance. Keep staging, installed-code recovery, credentials and journals private.

25 October first stable set: physical Core RC436 with independently native-updated Store1.0.0/SEO1.0.0 is accepted on the disposable lab. The stable catalogue and both release objects/ZIPs use the original key; original failed versions/revocations remain immutable. This does not publish a Core MU feed or authorize a client migration.

WordPress owns its twice-daily plugin check and the owner’s automatic-update choice. Kiwe adds no vendor call to ordinary visitor requests. The six-hour local catalogue cache survives an origin outage; installed engines never depend on current network availability. Expiry refuses new installation or update authority while existing Apps continue.

The signed catalogue is valid for seven days. The publisher must renew before expiry, even when no App code changes. Run `build-app-releases.mjs --output-root=<existing external release root> --channel=<candidate or stable> --renew-only=true` under the offline signing custodian, then publish and verify the channel file. This authenticates the prior catalogue and retains the exact independent release objects; it creates no new App ZIP/version. Renew every five days or sooner on release/revocation. A renewal does not erase a revocation or authorize a downgrade. Automated signing service/key custody and a continuously monitored production renewal job are not established by the local command alone.

The original AP ledger records actual native installation, update, recovery, physical absence and browser acceptance separately. No production rollout is authorized by a successful candidate publication.

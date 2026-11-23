# One signed Core release for the fleet

In **Build signed Kiwe MU release**, choose `profile=core` and enable `rollout_core`. One workflow run builds and signs Core once, then deploys that exact ZIP to every destination in the protected `kiwe-fleet` environment. Begin with a staging-only environment configuration, then qualify the actual owner-controlled production access before enabling it on live sites.

Each site's active Apps stay independent of the shared Core version. The native Core compatibility check verifies the existing signed App receipts before the first site changes. A missing or incompatible installed App blocks the release with the affected domain, so the maintainer can handle its App release separately.

## Environment

Restrict the production environment to trusted release refs. Keep `KIWE_UPDATE_ED25519_PRIVATE_PEM` in the established signing workflow's protected secret storage. Configure these values in the owner-controlled `kiwe-fleet` deployment environment.

| Secret | Value |
|---|---|
| `KIWE_FLEET_CONFIG` | JSON array with a record for every approved domain. |
| `KIWE_FLEET_SSH_CONFIG` | OpenSSH config with an alias for each approved hosting destination and the correct host, port and deploy user. |
| `KIWE_FLEET_SSH_KEY` | Owner-controlled deploy identity with access to the approved WordPress roots. |
| `KIWE_FLEET_SSH_KNOWN_HOSTS` | SSH host keys independently confirmed through the hosting account. |
| `KIWE_FLEET_BACKUP_PASSPHRASES` | JSON object mapping each domain to a separate recovery passphrase. Keep the values in the owner's recovery vault too. |
| `HOSTINGER_API_TOKEN` | Owner-controlled Hostinger authorization for the named sites' cache clearing operation. |

```json
[
  {
    "domain": "store.example",
    "sshAlias": "kiwe-store",
    "wordpressRoot": "/home/account/domains/store.example/public_html",
    "hostingerUsername": "u123456",
    "wpUser": "store-maintainer",
    "firstScroll": true
  },
  {
    "domain": "publisher.example",
    "sshAlias": "kiwe-publisher",
    "wordpressRoot": "/home/account/domains/publisher.example/public_html",
    "hostingerUsername": "u123456"
  }
]
```

Set `firstScroll` for sites whose Dock settings are managed in the rollout. Supply a WordPress account with `kiwe_manage_navigation` for those sites. Omit both fields for a site whose Dock settings should be kept. The current four WordPress installations use the independent Core profile. A future bundled site needs its separate signed App migration before joining the fleet.

## Verification and recovery

The runner checks every target, authenticates and transfers the signed packet into private hosting storage, then backs up every site that needs a change before the first switch. Each switch uses Kiwe's native updater, clears WordPress and Hostinger caches, and checks two ordinary homepage requests with no cache-busting query. The normal URL must serve the same Core as WordPress reports. The final pass checks the whole fleet again.

If any switch or verification fails, the runner restores attempted sites in reverse order and reports the run ID and any site needing recovery. Database snapshots are read back and authenticated, and manual database recovery remains separate from the automated Core rollback so recent orders stay in WordPress. The transfer files are removed while the recovery checkpoints remain in the site's private storage.

The same coordinator can run locally with owner-provisioned SSH aliases and environment secrets.

```sh
node tools/release/rollout-core-fleet.mjs /path/to/signed-release /path/to/private-fleet.json
node --test tools/release/test-core-fleet.mjs
```

This operation targets configured Hostinger WordPress sites. It uses the [Hostinger website cache API](https://github.com/hostinger/api-mcp-server/blob/main/src/core/tools/hosting.ts) and Kiwe's existing signature trust and App checks.

# One signed Core release for the fleet

In **Build signed Kiwe MU release**, choose `profile=core` and enable `rollout_core`. The default `rollout_target=staging` deploys the signed ZIP using the `kiwe-fleet-staging` environment. An approved production run selects `rollout_target=production` and takes its credentials from the separately protected `kiwe-fleet` environment. Staging rejects the four production domains before connecting to any host.

Each site's active Apps stay independent of the shared Core version. The native Core compatibility check verifies the existing signed App receipts before the first site changes. A missing or incompatible installed App blocks the release with the affected domain, so the maintainer can handle its App release separately.

## Environment

Restrict the production environment to trusted release refs. Keep `KIWE_UPDATE_ED25519_PRIVATE_PEM` in the established signing workflow's protected secret storage. Configure separate values for each owner-controlled deployment environment.

| Secret | Value |
|---|---|
| `KIWE_FLEET_CONFIG` | JSON array with a record for every approved domain. |
| `KIWE_FLEET_SSH_CONFIG` | OpenSSH config with an alias for each approved hosting destination and the correct host, port and deploy user. Let the runner supply the identity file. |
| `KIWE_FLEET_SSH_KEYS` | JSON object mapping each approved domain to its separate owner-controlled OpenSSH deploy key. |
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

If any switch or verification fails, the runner restores attempted sites in reverse order and reports the run ID and any site needing recovery. If WordPress cannot start, a standalone PHP step verifies and restores the previous Core files before the native settings check. Database snapshots are read back and authenticated. Manual database recovery remains separate from the automated Core rollback so recent orders stay in WordPress. The transfer files are removed while the recovery checkpoints remain in the site's private storage.

### Native SQL backup for large databases

An owner-operated workstation can use a native SQL backup when a table exceeds the portable archive's row limit. The release adapter's `prepare-sql-backup` action lists the site's WordPress tables, verifies that they use InnoDB and writes a mode 0600 database client file within the private work directory. The workstation adapter runs a native MariaDB dump with `--single-transaction --quick --skip-lock-tables --no-tablespaces --hex-blob`. It checks the gzip stream on the server and encrypts it with a checksum-pinned age binary and the owner's public recipient. The temporary client file and binary are removed after use. The owner workstation checks the transferred ciphertext hash and decrypts it as a stream to verify the gzip data, table inventory and dump completion marker.

Transfer the verified `database.sql.gz.age` file into the release's private work directory. Send the helper's JSON receipt to `backup-sql` on standard input. The adapter checks the file length, SHA-256 and table inventory before keeping the existing Core recovery checkpoint. The encrypted archive can also be kept on the owner's workstation. Keep the vault identity on the owner-controlled machine. Restore SQL only to an isolated database during a recovery rehearsal. Live database restoration remains an explicit owner decision.

The same coordinator can run locally with owner-provisioned SSH aliases and environment secrets. On a Hostinger shared account, the Windows workstation adapter can reuse a laptop-bound key and a Hostinger MCP cache connection. The key has the scope of the hosting account. Keep it in the owner profile and verify the provider's host fingerprint before connecting.

```sh
KIWE_FLEET_TARGET=staging node tools/release/rollout-core-fleet.mjs /path/to/signed-release /path/to/private-fleet.json
node --test tools/release/test-core-fleet.mjs
```

The workstation adapter takes the deployment environment, update mode, private laptop and site configuration, signed release directory, and cache acknowledgement directory. Its `rehearse` and `rehearse-sql` modes require a single staging site and restore it after verification. Its `apply` mode runs the coordinated install. The process reads site-specific backup passphrases from `KIWE_LOCAL_FLEET_BACKUP_PHRASES`. It emits a JSON `cache` event for each provider purge request. The authenticated caller clears that site's cache and writes `{"ok":true}` to the request ID's JSON file in the supplied directory. The process records the signed package digest and per-site verification in `proof.json`. Supply the proof file of a failed backup-only run as the optional final argument to resume that run. Every reusable checkpoint is checked against the current Core files, Dock setting, package backup and encrypted database before the rollout continues.

This operation targets configured Hostinger WordPress sites. It uses the [Hostinger website cache API](https://github.com/hostinger/api-mcp-server/blob/main/src/core/tools/hosting.ts) and Kiwe's existing signature trust and App checks.

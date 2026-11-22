# Core493 Dock and startup candidate

Core493 builds on RC492 source commit `85cf89a680fede857f630eb68aac0187f619d891`. It starts the existing Surface script download earlier, remembers the first Sheet press during startup, and lets public Search open during the private account request. The Search cart action uses the current native session.

## First-scroll Dock

Open **App → Dock** and enable **Show the Dock after the first scroll**. The Dock slides up to its saved position on the first page scroll and stays visible for that visit. On pages that fit within the viewport it is available on load. Reduced-motion preferences use an instant reveal. The setting defaults to off.

Kiwe MCP's existing `mcp.appshell.change` operation accepts `reveal_on_scroll` in the `dock` group. Signed-in Dock operators can also export and apply a portable profile at `/wp-json/dsa/v1/workspace/dock/profile`. Read the destination revision and send it as `expected` with the `profile` in a POST through the native nonce and mutation-proof boundary.

To update the single setting, use this profile.

```json
{
  "schema": "kiwe.dock-profile.v1",
  "choices": {
    "dock": {
      "reveal_on_scroll": true
    }
  }
}
```

## Core package

Build the physical Core profile for installations that use separate Kiwe Apps. The reviewed package has 609 manifest members and 612 ZIP entries.

```sh
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
node tools/release/build-apps-ownership.cjs --check
node tools/release/build-core-app-profile.mjs --output-root=../kiwe-core-rc493
```

The source package, Dock as a native setting, private profile routes, SiteGraph with an attributed launcher and no Dock, public Search startup, and first-scroll behavior in desktop and mobile Chrome have local verification. Qualify signed installation and recovery on the WordPress lab before updating the live cohort.

The release custodian signs the exact Core ZIP using the existing Kiwe Ed25519 key. The signed `packageManifestSha256` must identify the physical Core manifest inside the ZIP. Follow the [release runbook](RELEASE-RUNBOOK.md) and [RC492 source guide](RC492-SOURCE.md) for the release and App layout. For a site moving from a bundled installation, verify and stage the required separately signed Apps before switching its Core.

The manual **Build signed Kiwe MU release** workflow accepts the **core** profile. It prepares the physical archive and signs it using the repository's `KIWE_UPDATE_ED25519_PRIVATE_PEM` secret. It returns the signed ZIP and release manifest as a workflow artifact.

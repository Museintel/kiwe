# Portable development and release

Kiwe development is repository- and service-backed. No runtime or release operation requires a particular workstation.

## Canonical repositories

- Kiwe: `https://github.com/Museintel/kiwe`
- Key.kiwe: `https://github.com/Museintel/key.kiwe`
- Seam Compiler: `https://github.com/Museintel/seam-compiler`

Clone the repositories independently. Generated output, deployment archives, dependencies, runtime state, credentials, and private keys are intentionally excluded from Git.

## Kiwe MU releases

The public update key is tracked at `tools/release/kiwe-update-public-key.json`. The matching private key is stored only as the protected GitHub Actions secret `KIWE_UPDATE_ED25519_PRIVATE_PEM`; it must never be committed.

Run the **Build signed Kiwe MU release** workflow from GitHub Actions and choose `candidate` or `stable`. The workflow verifies the source and publishes a downloadable `kiwe-<channel>-signed-feed` artifact containing the static `app.kiwelaunch.com` feed. Deploy that artifact to `app.kiwelaunch.com` through the authenticated Hostinger account.

For an authorized local release, provide the same key through the `KIWE_UPDATE_ED25519_PRIVATE_PEM` environment variable and run:

```sh
node tools/release/build-signed-update.mjs --channel=candidate
```

## Runtime configuration

- Key.kiwe secrets, WhatsApp session material, and tenant state live in Hostinger environment variables and the persistent state directory outside the deployment root. See the Key.kiwe repository documentation.
- Seam Compiler has no workstation-bound runtime path. Install from `package-lock.json`, run `npm test`, and deploy the tracked source as a Node application.
- WordPress credentials and site-specific Kiwe settings remain on their WordPress sites; they are not repository content.

Historical proof documents may mention the workstation paths used to capture evidence. Those strings are provenance only and are not executable dependencies.

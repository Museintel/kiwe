# Kiwe RC492 source

Public Git source updated 22 November 2026. The original signed release uses Kiwe Core `8.0.0-rc.492`, Store `1.1.16` and MCP `1.0.0-rc.31`. The published package manifests for both shops matched those signed archives byte for byte when checked on that date. [Dharwad Core manifest](https://dharwadpedha.com/wp-content/mu-plugins/dsa/package-manifest.json), [National Core manifest](https://nationalchikki.com/wp-content/mu-plugins/dsa/package-manifest.json).

| Component | Signed ZIP SHA-256 |
| --- | --- |
| Core RC492 | `c1c50596512eae5367edb3b099bfef77afad8987eb5bc47d513009c38b069cd4` |
| Store 1.1.16 | `a4641acfa9291e63bc743b43a8977e4cf1b120a2439a8672c03382e4fb6b27fd` |
| MCP 1.0.0-rc.31 | `003267aca647cc20a8426ae89e7ce3dfc325df5a2152f6790ce492ae80065ed3` |

The source tree contains the bundled development profile, including files for each optional App. The stores use the separate Core profile and separately installed Apps. Use the Core profile builder to reproduce the live Core ZIP. Give it a new output directory outside the checkout.

```sh
node tools/release/verify-package.cjs
node tools/release/build-apps-ownership.cjs --check
node tools/release/build-core-app-profile.mjs --output-root=../kiwe-core-rc492
```

The reproduced Core ZIP matched the signed archive's SHA-256. The public source includes the exact generated MCP dependencies, with their licences and verification manifest. Store and MCP App ownership, provider descriptors and source members were verified against their signed packages. The loader's WordPress display header in the signed release reads `8.0.0-rc.490`. Its runtime version constant and the package manifest both identify `8.0.0-rc.492`.

The source checks use Composer 2.10.2 to install the locked dependencies from `packages/kiwe-mcp-runtime/composer.lock`. Verification allows Composer to record a different checkout reference for the Kiwe root package. It checks the version and reference of each dependency and validates the hash of every shipped vendor file.

See the [release runbook](RELEASE-RUNBOOK.md) and [App release operations](APPS-RELEASE-OPERATIONS.md) for signed package handling. Use the matching signed archives for installation and the Git checkout for development.

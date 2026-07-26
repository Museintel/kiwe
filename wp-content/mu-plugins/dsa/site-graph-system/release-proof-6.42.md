# Kiwe 6.42 release proof

Date: 2026-07-27

## Scope

This release turns Kiwe's public AI toolkit into a cleaner command-shell entrypoint for browser AIs.

It does not add runtime cart, checkout, PhoneKey, SecureTrack, WooCommerce, payment, service worker, Bricks save, or DSA Geometry Engine authority.

## Added

- Terminal-style entry pattern:

```text
explore: https://github.com/Museintel/kiwe
/list
```

- `listCommands().terminalEntry` metadata so CLI/MCP/browser clients can display the exact starter pattern.
- Public documentation that `explore:` is a location pointer, not permission to crawl the whole repository.

## Hardened

- Command diagnostics strip `explore:` lines before slash-token parsing.
- `/usecompanion` stripping now also ignores `explore:` lines.
- GitHub URL path segments cannot be mistaken for Kiwe commands.

## Local proof

Run before release:

```bash
node --check kiwe-ai-toolkit/lib/kiwe-core.js
node --check kiwe-ai-toolkit/bin/kiwe.js
node --check kiwe-ai-toolkit/mcp/index.js
node tools/release/verify-package.cjs
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
```

Import-level assertions should confirm:

- `explore: https://github.com/Museintel/kiwe` followed by `/list` routes to the command list.
- `explore:` followed by `/usesitegraph /nonai` still asks for target Site Graph truth instead of scraping.
- `listCommands().terminalEntry.pattern` advertises the starter command.

## Post-upload checks

After the package is uploaded to staging:

1. Confirm `/wp-json/dsa/v1/manifest` reports `6.42`.
2. Ask a browser AI:

```text
explore: https://github.com/Museintel/kiwe
/list
```

3. Confirm it reads only the public entrypoint/workflow file, prints the command list, and waits.
4. Confirm it does not clone, crawl, or search the repository.

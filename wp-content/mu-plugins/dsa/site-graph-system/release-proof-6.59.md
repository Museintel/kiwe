# Kiwe 6.59 accessibility dark-mode routing proof

## Reason

Browser-AI testing showed a model still searched GitHub docs and cloned the repository while trying to understand `/fix /accessibility` dark-mode token remapping.

## Changes in 6.59

- Tightened `kiwe-ai-toolkit/command-manifest.json`:
  - explicitly says not to clone the repository for slash-command lanes;
  - says not to search GitHub docs for hidden contracts;
  - embeds a `/fix /accessibility` `darkModeRecipe`.
- Tightened `accessibility-lite.md`:
  - includes a self-contained dark-mode token remap recipe;
  - says the context is complete and no separate dark-mode repo contract is needed;
  - gives the exact `[data-kiwe-theme="dark"]` / `html[data-kiwe-theme="dark"]` selector pattern;
  - explains how existing project variables such as `--nc-paper`, `--nc-surface`, `--nc-ink`, and `--nc-muted` map through Kiwe tokens.
- Tightened `workflow-lite.md`:
  - says not to clone, crawl, or read the full Kiwe repository.
- Improved deterministic validator messages:
  - missing dark-mode proof now points to the accessibility-lite dark-mode token remap recipe instead of leaving models to search docs.
- Mirrored the same message in the PHP/API accessibility validator.
- Added connector contract checks so no-clone and dark-mode recipe guidance cannot silently disappear.

## Local validation

```text
php -l wp-content/mu-plugins/dsa/includes/AI/Accessibility_Validator.php
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

Expected:

- PHP syntax passes.
- Toolkit tests pass.
- Connector contracts pass.
- Package manifest includes the updated command manifest and reports version `6.59`.
- Package verifier reports `Package 6.59` with all files verified.

## Browser AI smoke expectation

For:

```text
explore: https://github.com/Museintel/kiwe
/fix /accessibility
```

Expected:

- no repo clone;
- no broad docs search;
- read `command-manifest.json`;
- read `accessibility-lite.md` and `seam-attributes-lite.md`;
- use the embedded dark-mode token remap recipe;
- preserve Bricks/Seam/DSA structure unless a documented accessibility-token exception is unavoidable;
- return compact status and fixed artifact files only.

# Kiwe / DSA Release Proof 6.68

Date: 2026-07-29

## Scope

6.68 makes SeamFlow audit-driven from any starting point.

Browser AI, IDE AI, MCP clients, and skill-capable agents must not treat a generated artifact as complete because it looks correct or because a previous attempt passed. The closure signal is the current artifact's required `/audit` lane returning `PASS` after any needed `/fix` loops.

The public contract now reports:

```text
SeamFlow contract: 6.68
```

## Audit closure law

SeamFlow closes only when the required `/audit` command for the detected current artifact lane returns `PASS`.

The loop is:

```text
/audit current-lane
/fix current-lane     # only when audit fails
/audit current-lane   # same audit again
repeat until PASS or stop as NEEDS_INPUT
```

Stop as `NEEDS_INPUT` when the same blocker repeats, required source is missing, live authority/API access is missing, or the official validator cannot be run and the audit context still leaves unresolved blockers.

## Starting from the middle

SeamFlow detects the current artifact by content, not filename, then uses the matching closure audits:

```text
raw HTML/CSS/JS draft      -> /audit /seamframework, /audit /frameworkprofile, /audit /bricksconversion, /audit /accessibility
Seam page artifact         -> /audit /seamframework, /audit /frameworkprofile, /audit /bricksconversion, /audit /accessibility
Framework profile          -> /audit /frameworkprofile
Bricks template/conversion -> /audit /bricksconversion, /audit /accessibility
DSA theme package          -> /audit /dsatheme, /audit /accessibility
combined handoff           -> /audit /combined, /audit /accessibility
```

That means a user can begin from raw HTML, a Seam rebuild, a Framework profile, a native Bricks template, a DSA theme, or a combined handoff. SeamFlow resumes from that point and uses audit/fix loops as the closing indicator.

## Audit cadence

```text
/auditateachstep
```

Each phase must pass its own audit before the next phase starts. This is the recommended setting for production/importable files.

```text
/auditatend
```

Creation/conversion phases may run first, but final delivery still requires every relevant closing audit to pass.

In both modes, `/fix` is mandatory after a failed audit, and the same `/audit` must be rerun after the fix.

## Verification commands

Run from repository root:

```text
npm test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
php -l wp-content/mu-plugins/dsa.php
php -l wp-content/mu-plugins/dsa/dsa.php
```

# Kiwe / DSA Release Proof 6.67

Date: 2026-07-29

## Scope

6.67 tightens the **SeamFlow** external-AI command shell after browser-AI testing showed two failure modes:

- browser AI treated `step-by-step` and `full-flow` as loose prose instead of explicit commands;
- browser AI reused prior National Chikki/BioVantage validation material instead of auditing the current uploaded files.

The public contract now reports:

```text
SeamFlow contract: 6.67
```

## Correct first response

When a human supplies files but no slash command, SeamFlow must respond in this order:

```text
STATUS: NEEDS_INPUT
SeamFlow contract: 6.67
Attachments detected: yes/no
Artifact diagnostic: type/confidence/stage, if files are present and inspectable
Recommended next command:
Question: choose /execute /stepbystep, /execute /fullflow, or a specific /command. Optional flags: /auditateachstep, /auditatend, /usecompanion.
Commands: use /list for the compact command list
```

`/list` is intentionally last so the human first sees the file diagnosis and execution choice, then knows where to discover the full command vocabulary.

## Execution command vocabulary

New first-class command tokens:

```text
/execute /stepbystep
/execute /fullflow
```

New execution flags:

```text
/auditateachstep
/auditatend
/usecompanion
```

`/auditateachstep` and `/auditatend` are not standalone generation or audit commands. They must be paired with `/execute /stepbystep` or `/execute /fullflow`.

## Current-run evidence boundary

SeamFlow must classify and validate the current artifacts only.

Do not use prior Kiwe validation material, old National Chikki/BioVantage attempts, previous browser-AI outputs, local downloads, search results, or accepted notes unless the human supplied those exact files in the current turn or explicitly asked for comparison.

This prevents browser AI from passing a new test by inheriting an old result.

## Companion handshake

If the human chooses `/usecompanion` and no Kiwe MCP/tool is already connected, ask for:

```text
KIWE_REST_BASE
KIWE_AI_KEY
```

Then make one bounded Companion status/context call.

On success, report:

```text
COMPANION: connected
```

with compact route/hash proof before continuing.

On failure or missing credentials, report:

```text
COMPANION: fallback
```

and continue without blocking the selected SeamFlow command.

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

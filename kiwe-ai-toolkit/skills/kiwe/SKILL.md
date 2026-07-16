---
name: kiwe
description: Use when creating Kiwe website/page, DSA AppShell theme, or combined handoffs. The skill tells the agent to use compact Kiwe toolkit context and avoid reading the full plugin codebase.
---

# Kiwe

Use Kiwe AI Toolkit instead of reading the full Kiwe/DSA plugin codebase.

## Modes

- `website`: create a normal WordPress/Bricks website/page handoff using Seam Framework.
- `theme`: create a Kiwe DSA/AppShell theme handoff.
- `combined`: create both website/page and AppShell theme, with optional Kiwe settings profile.

## Required behavior

1. Pick the correct mode.
2. Use the compact mode context from `kiwe_get_context` or `node bin/kiwe.js context <mode>`.
3. Use `kiwe_create_handoff` or `node bin/kiwe.js create <mode> <output-dir>` to scaffold output.
4. Keep website/page files, AppShell theme files, and Kiwe settings/profile files separate.
5. Do not create cart, checkout, auth, save, search, AI, service-worker, history, focus, or WooCommerce authority.
6. Validate the final folder.

## Never do this

- Do not read the full plugin codebase unless explicitly asked by the project lead.
- Do not copy runtime PHP/JS internals into the handoff.
- Do not turn Seam Class Vocabulary into starter visual recipes.

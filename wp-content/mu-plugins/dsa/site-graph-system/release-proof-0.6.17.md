# Kiwe DSA 0.6.17 release proof

Date: 2026-07-22

Purpose: close the first live `0.6.16` AI/theme loop after staging confirmed native AI, Site Graph, Companion, Bricks conversion, and live DSA sheets were working but exposed three refinements.

## Live observations

- Staging served package manifest `0.6.16`.
- Active theme record reported `national-heritage-commerce` as active.
- Live National cart sheet applied National theme values: warm surface, "Your tea-time bag" title, FBT rail, and National dock styling.
- The sheet grabber/close handle sat too far below the top edge because it followed the panel's large responsive top gutter.
- Native Studio Gemini drafting worked with HTTP 200, but prompt estimates still reached about 90KB because compaction checked raw context size before provider prompt framing.
- Site Graph Data compact `resources` batch worked. Direct product GET returned product items but still labelled the envelope as `posts`.
- National Chikki v4.8's primary combined preview used private fixture wrappers that live Kiwe core does not render, explaining why the handoff preview and installed sheet did not visually match one-to-one.

## Changes proved locally

- Core `.dsa-sheet-grabber` now counterbalances the responsive gutter so the visible handle sits near the sheet edge while keeping its touch target.
- Studio context trimming now targets a smaller native context budget to reserve provider prompt overhead.
- Site Graph Data product/page/custom-post reads now return resource labels aligned to the requested post type.
- Combined-mode validator fails primary previews that use private AppShell fixture wrappers such as `.dsa-screen-head`, `.dsa-screen-body`, `.dsa-profile-card`, `.dsa-score-card`, `.dsa-links-identity`, `.dsa-account-rows`, `.dsa-link-list`, `.dsa-install-steps`, or `.dsa-game-frame`.
- The tightened validator was tested against National Chikki v4.8 and correctly failed the preview/live mismatch.

## Expected verification before upload

- PHP syntax passes for changed PHP files.
- `node kiwe-ai-toolkit/tools/validate-output.cjs <national-v4.8> --mode combined` fails with the expected private-fixture report.
- Connector contracts and runtime release contracts pass.
- Package manifest is rebuilt and verifies as `0.6.17`.

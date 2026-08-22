# Design Context-aware ideation proof — Kiwe 7.22

Kiwe 7.22 makes the owner-authored Design Context the evidence layer for
creative `/ideate` sessions without turning it into a visual template or a
Framework dependency.

Bare `/ideate` recognizes either:

- a `kiwe.sitegraph-design-context.v1` export; or
- a standalone `kiwe.seam-design-context.v1` owner brief.

The browser AI must keep three authority classes separate:

- owner facts are locked;
- owner preferences are preserved unless the human explicitly revises them;
- layout, typography, imagery, motion, section rhythm and unfilled palette
  roles remain a draft-only creative workspace.

Future SiteGraph Design Context exports include a self-describing
`kiwe.ideation-context.v1` contract. It grants no WordPress mutation,
publishing, private-data or Framework authority. The AI asks only for the
new/redesign/extension relationship, reusable versus inspiration material,
and material creative gaps not answered by the owner context.

`/ideate` remains Framework-neutral. It produces only `index.html`,
`styles.css` and `script.js`; Seam Framework remains a later opt-in compiler
stage.

Verification:

```text
node kiwe-ai-toolkit/tools/smoke-test.cjs
node tools/release/sitegraph-design-context-contracts.cjs
node tools/release/onboarding-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-green-baseline.cjs
```

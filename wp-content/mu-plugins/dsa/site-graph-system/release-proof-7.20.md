# Design Context enhancement proof — Kiwe 7.20

Kiwe 7.20 separates owner evidence from AI enhancement. The SiteGraph Design
Context export now contains an exact `ownerContextHash`, a locked/writable
authority contract and a template for `kiwe.design-context-enhancement.v1`.
Kiwe > Framework validates that file, rejects stale context, rejects any claim
to overwrite owner evidence, and prevents Framework color overrides from
conflicting with an owner-selected semantic color.

Approved suggestions are stored in their own WordPress option. The original
owner profile is never rewritten. Resolved SiteGraph, Bricks, WordPress 7
bindings and native SEO may use approved editorial wording and fill semantic
color roles the owner left empty. Seam Framework remains explicit opt-in.

Onboarding now starts the planned-page section with one progressive row rather
than eight blanks. It also captures bounded, owner-answerable SEO facts: legal
name, founding year, primary website goal, likely customer search intent and
verifiable proof points. No meta-keyword field or automatic claim publishing is
introduced. The DSA Links score continues to honor a manual score; when it is
blank, completed onboarding may provide the clearly labelled SEO-readiness
score.

Verification:

```text
node tools/release/onboarding-contracts.cjs
node tools/release/sitegraph-design-context-contracts.cjs
node tools/release/full-regression.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```

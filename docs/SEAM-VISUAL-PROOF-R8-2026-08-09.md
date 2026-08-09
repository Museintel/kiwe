# SEAM R8 visual proof — National Chikki

Date: 2026-08-09  
Target: `https://plum-giraffe-539524.hostingersite.com/`  
Matrix: 1440px, 820px, and 390px; light/default state; full-page Chromium screenshots.

## Rendered pixel fidelity

The percentages below are `100 × (1 − YIQ mismatch ratio)` with a per-pixel delta threshold of `0.1`. They are matrix-scoped screenshot measurements, not universal fidelity claims and not the converter's pre-render confidence estimate.

| Page | 1440px | 820px | 390px | Page mean |
|---|---:|---:|---:|---:|
| Home | 79.40% | 69.80% | 62.94% | 70.71% |
| About | 79.08% | 52.07% | 62.67% | 64.61% |
| Contact | 79.65% | 80.45% | 64.60% | 74.90% |
| Shop | 76.44% | 66.57% | 51.28% | 64.76% |
| Viewport mean | 78.64% | 67.22% | 60.37% | **68.74%** |

## Findings

1. Old Bricks class batches were absent. The remaining drift is not stale R3–R7 CSS.
2. Bricks adds `bricks-lazy-hidden`, whose `background-image: none !important` rule remains until its intersection observer runs. Smooth scrolling caused the first proof pass to skip those reveals. SEAM Capture now temporarily forces instant scrolling, settles the observer, and restores authored scrolling before evidence collection.
3. The R8 compiler resolved reusable CSS variables to their current desktop value. This froze responsive gutters and would also freeze theme-dependent values. R9 preserves `var(...)` through native Bricks controls and uses scoped exact CSS only where a structured Bricks control cannot express it.
4. Runtime-created images could reuse relative paths held in `data-*` attributes, producing eight missing asset URLs on Home and eight on Shop. Project bundling now embeds URL-valued `data-*`, asset download links, and inline-style URLs.
5. R8 elements did not retain capture-compatible proof provenance, so R8 geometry/style/accessibility anchor coverage is not trustworthy. R9 emits `data-seam-proof-node` using the same deterministic DOM-path hash as SEAM Capture.
6. The live Kiwe AppShell dock is present in candidate screenshots but absent from the supplied reference. Future proof reports must publish both compiler-only fidelity and composed AppSite fidelity rather than silently mixing the two surfaces.

## Capture-plane improvements

- Added proof-only capture mode that retains screenshot, geometry, computed style, semantics, diagnostics, and resource integrity while omitting compiler-only cascade inventories.
- Replaced per-element stylesheet walking with a per-rule match index.
- Bounded font/image settlement and made below-fold lazy-image triggering deterministic.
- Added independently runnable viewport shards and a validated merger that checks source identity, engine settings, screenshot hashes, duplicate viewports, and resource consistency.

## Gate

R8 remains a useful editable native-Bricks baseline but does not meet a high-fidelity release gate. Generate and deploy R9, then repeat this matrix with the new proof anchors. SiteGraph should begin after R9's rendered baseline and remaining repair plan are recorded.

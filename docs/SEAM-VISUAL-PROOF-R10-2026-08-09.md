# SEAM visual proof — R10 — 2026-08-09

## Decision

R10 does **not** pass the visual-fidelity gate. SiteGraph remains deferred.

The release fixes the project-composition defect that rendered a page bundle's embedded header and footer together with Bricks' conditioned Header and Footer templates. The public site now renders one header and one footer, and the Home bento cards retain their border radius. This correction is general: it is based on explicit `header.html` and `footer.html` include provenance, not National Chikki selectors, class names, text, or geometry.

The remaining gaps are converter fidelity gaps, especially responsive geometry and content flow on About and Shop. They are not explained by stale Bricks classes alone.

## Test scope

- Pages: Home, About, Contact, Shop
- Viewports: 1440 px, 820 px, 390 px
- Matrix: 12 states
- Reference: rendered source HTML/CSS/JS captures from R8
- Candidate: public Bricks pages after R10 content replacement, conditioned Header/Footer, compiler batch cleanup, Bricks CSS regeneration, and Kiwe cache clearing
- Candidate templates: four R10 content templates plus the retained conditioned R9 Header and Footer templates
- Class namespace retained: `seam-1s42i-`

Raw evidence is in `C:\Users\shariq\Documents\Codex\2026-08-01\visual-proof-r10-20260809`.

## Results

| Page | 1440 px | 820 px | 390 px | Page average |
| --- | ---: | ---: | ---: | ---: |
| Home | 65.96% | 64.22% | 67.63% | 65.94% |
| About | 64.26% | 48.60% | 45.78% | 52.88% |
| Contact | 82.20% | 76.23% | 60.52% | 72.98% |
| Shop | 79.06% | 65.47% | 55.37% | 66.64% |
| **Overall** |  |  |  | **64.61%** |

Supporting structural signals across all 12 states:

- Pixel accuracy: **64.61%**
- Native/computed-style agreement: **86.65%**
- Proof-anchor coverage: **68.82%**
- Mean matched-box delta: **42.83 px**
- Passed states: **0 / 12**

Worst recorded values by page:

| Page | Worst pixel mismatch | Anchor coverage | Worst box delta | Style mismatch | Accessibility mismatches |
| --- | ---: | ---: | ---: | ---: | ---: |
| Home | 35.78% | 75.63% | 590.00 px | 14.50% | 252 |
| About | 54.22% | 70.45% | 636.73 px | 12.03% | 78 |
| Contact | 39.48% | 59.51% | 603.48 px | 12.22% | 111 |
| Shop | 44.63% | 69.68% | 461.58 px | 14.65% | 279 |

R8's pixel-only overall result was 68.74%. R10 is 4.13 percentage points lower on the current capture, despite correcting duplicate site chrome. Therefore the R10 composition fix is accepted, but no general fidelity improvement is claimed. Dynamic AppShell content and browser/runtime differences remain measurement confounders; future proof runs must publish both compiler-only and composed-AppSite scores.

## Diagnosis

1. **Resolved — project chrome ownership.** Source projects commonly include reusable Header/Footer fragments so their static previews are complete. Bricks content templates must omit those fragments when conditioned Bricks Header/Footer templates own site chrome.
2. **Not stale-class driven.** Older compiler classes were removed and the current namespace was retained before capture. Responsive failures persist, so class leakage is not the primary cause.
3. **Responsive geometry remains the largest defect.** About and Shop lose substantial accuracy at 820 px and 390 px. Large box deltas show that vertical flow, intrinsic sizing, overflow, and breakpoint behavior are not yet reproduced reliably.
4. **Style inference is materially stronger than geometry.** The 86.65% style agreement indicates that native Bricks style translation is useful, but incorrect boxes and flow amplify full-page pixel differences.
5. **Accessibility is not ready for automatic approval.** The proof system reports foreground/background, text, and semantic mismatches. Accessibility and dark/light mode must remain an explicit post-conversion stage with a separate gate.

## R10 implementation

- Project includes now carry provenance when their resolved path is `header.html` or `footer.html`.
- Content-template compilation removes only those provenance-marked imported roots.
- Standalone pasted documents, semantic headers inside page content, and unrelated includes remain untouched.
- A deterministic migration utility repaired already-generated R9 content templates without changing their class namespace or proof anchors.
- Live templates were reduced to exactly six active assets: Home, About, Contact, Shop, Header, Footer.

## Gate and next repair batch

Do not begin SiteGraph from this result. R11 should target general responsive fidelity in this order:

1. infer intrinsic aspect ratios and replaced-element sizing;
2. translate CSS grid track/minmax/auto-placement and flex min-size behavior into native Bricks controls;
3. preserve overflow, stacking context, absolute-position containing blocks, and breakpoint overrides;
4. add compiler-only capture alongside composed AppSite capture;
5. rerun the same 12-state matrix and require measurable improvement before adding another website corpus.

No universal 100% fidelity claim is authorized by this report.

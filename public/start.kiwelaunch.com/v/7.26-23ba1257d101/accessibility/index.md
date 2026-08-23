# /accessibility

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/accessibility/
Machine contract: https://start.kiwelaunch.com/accessibility/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/accessibility/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

accessibility-current-artifact

## Hosted resources

- https://start.kiwelaunch.com/contexts/accessibility-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/contexts/accessibility-lite.md

## Requirements

- current page, preview, artifact, or file map; the active /ideate page qualifies

## Required behavior

- treat the approved current artifact and light appearance as design authority
- run inspect -> audit -> minimal fix -> render -> re-audit
- inspect the complete artifact in light and dark at desktop, tablet, mobile and narrow widths
- discover responsive defects without requiring the human to name them
- check card flex/grid growth, repeated-component sizing and CTA/footer alignment
- check wrapping, clipping, overlap, viewport overflow and touch-control collisions
- preserve content, hierarchy, identity, interaction intent, distinctive composition and light-mode art direction
- use bounded visual judgment to create a semantic dark appearance with peer hierarchy, brand recognition and intentional surface/accent distribution
- run validate-accessibility.cjs <artifact-root> --closure before PASS

## Outputs

- revised existing artifact file(s)
- accessibility/kiwe-accessibility-plan.json
- SEAM automated accessibility score with automated coverage
- responsive reflow/alignment findings
- separate dark-mode visual-parity review
- manual checks outside the score

## Forbidden

- influencing /ideate art direction
- forcing Seam Framework
- forcing Bricks conversion
- generic redesign
- copy or storytelling rewrite
- light-mode redesign
- dark-mode inversion
- claiming subjective beauty or user preference is scientifically certified
- unmeasured compliance claims
- ZIP

## Final response

Return technical accessibility status separately from dark-mode visual-parity review; include files changed, structural drift, validator/render proof, and remaining human/device checks.

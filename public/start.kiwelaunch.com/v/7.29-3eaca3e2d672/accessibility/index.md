# /accessibility

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/accessibility/
Machine contract: https://start.kiwelaunch.com/accessibility/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/accessibility/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

accessibility-current-artifact

## Hosted resources

- https://start.kiwelaunch.com/contexts/accessibility-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/accessibility-lite.md

## Requirements

- current page, preview, artifact, or file map; the active /ideate page qualifies

## Required behavior

- freshly discover the current release and record its contractVersion, releaseId and sourceHash in plan.execution
- keep execution.command exactly /accessibility and execution.mode closed-refinement
- treat the approved current artifact and light appearance as design authority
- run inspect -> audit -> minimal fix -> render -> re-audit
- repair safely discoverable failures in the artifact rather than merely recommend fixes
- create or complete a deliberate peer dark appearance when dark mode is absent
- inspect the complete artifact in light and dark at desktop, tablet, mobile and narrow widths
- discover responsive defects without requiring the human to name them
- check card flex/grid growth, repeated-component sizing and CTA/footer alignment
- check wrapping, clipping, overlap, viewport overflow and touch-control collisions
- preserve content, hierarchy, identity, interaction intent, distinctive composition and light-mode art direction
- use bounded visual judgment to create a semantic dark appearance with peer hierarchy, brand recognition and intentional surface/accent distribution
- run validate-accessibility.cjs <artifact-root> --closure before PASS

## Outputs

- revised existing artifact file(s), or the unchanged proven artifact only when no failure exists
- accessibility/kiwe-accessibility-plan.json with execution receipt and closure
- SEAM automated accessibility score with automated coverage
- responsive reflow/alignment findings
- separate dark-mode visual-parity review
- manual checks outside the score

## Forbidden

- routing or labelling bare /accessibility as /audit /accessibility
- mutation_performed false while safely fixable failures remain open
- dark mode not implemented/proven as a completed outcome
- audit complete without returned artifact and closure proof
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

Never say audit complete for bare /accessibility. Return the artifact, technical accessibility status separately from dark-mode visual-parity review, fresh execution receipt, structural drift, validator/render proof, and remaining human/device checks.

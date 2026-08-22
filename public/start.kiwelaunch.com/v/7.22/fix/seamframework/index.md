# /fix /seamframework

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/seamframework/
Machine contract: https://start.kiwelaunch.com/fix/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/fix/seamframework/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.22/contexts/workflow-lite.md
- https://start.kiwelaunch.com/contexts/seam-attributes-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.22/contexts/seam-attributes-lite.md
- https://start.kiwelaunch.com/validators/validate-seamframework.cjs
  - Pinned: https://start.kiwelaunch.com/v/7.22/validators/validate-seamframework.cjs

## Requirements

- failed Seam page artifact and audit findings

## Required behavior

- fix the actual Seam page artifact
- move visual CSS off bare seam-* selectors and onto project-owned classes
- rerun the same Seam validator until PASS or NEEDS_INPUT

## Outputs

- website/bricks-paste.html

## Forbidden

- Bricks JSON
- DSA theme
- redesign
- docs unless /document
- ZIP

## Final response

Return fixed Seam page artifact and compact validator-proof status.

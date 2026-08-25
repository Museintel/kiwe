# /fix /seamframework

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/fix/seamframework/
Machine contract: https://start.kiwelaunch.com/fix/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/fix/seamframework/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/workflow-lite.md
- https://start.kiwelaunch.com/contexts/seam-attributes-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/seam-attributes-lite.md
- https://start.kiwelaunch.com/validators/validate-seamframework.cjs
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/validators/validate-seamframework.cjs

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

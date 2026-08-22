# /fix /seamframework

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/seamframework/
Machine contract: https://start.kiwelaunch.com/fix/seamframework/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

fix

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

# /fix /previousaudit

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/previousaudit/
Machine contract: https://start.kiwelaunch.com/fix/previousaudit/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

fix

## Requirements

- immediately previous audit findings and current artifact files

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- corrected files for the previously audited failed lanes only

## Forbidden

- creative rebuild
- redesign
- new lane creation
- docs unless /document
- ZIP
- stale files
- fixing from memory without previous findings

## Final response

Return corrected files only plus compact status after rerunning the exact previous audit scope.

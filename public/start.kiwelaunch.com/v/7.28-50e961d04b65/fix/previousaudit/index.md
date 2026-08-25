# /fix /previousaudit

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/fix/previousaudit/
Machine contract: https://start.kiwelaunch.com/fix/previousaudit/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/fix/previousaudit/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/command-manifest.json

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

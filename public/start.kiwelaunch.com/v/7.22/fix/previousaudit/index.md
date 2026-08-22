# /fix /previousaudit

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/previousaudit/
Machine contract: https://start.kiwelaunch.com/fix/previousaudit/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/fix/previousaudit/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/command-manifest.json

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

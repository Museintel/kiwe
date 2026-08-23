# /fix /previousaudit

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/fix/previousaudit/
Machine contract: https://start.kiwelaunch.com/fix/previousaudit/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/fix/previousaudit/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/command-manifest.json

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

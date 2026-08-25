# /fix /previousaudit

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/fix/previousaudit/
Machine contract: https://start.kiwelaunch.com/fix/previousaudit/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/fix/previousaudit/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/command-manifest.json

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

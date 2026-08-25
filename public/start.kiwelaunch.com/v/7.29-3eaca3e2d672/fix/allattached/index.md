# /fix /allattached

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/fix/allattached/
Machine contract: https://start.kiwelaunch.com/fix/allattached/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/fix/allattached/contract.json
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

- current attached/supplied artifacts and failed audit findings or rerunnable validators

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- corrected current artifact files only

## Forbidden

- creative rebuild
- redesign
- DSA/combined expansion unless already present
- docs unless /document
- ZIP
- stale files

## Final response

Return corrected files only plus compact audit status after rerunning every matching lane audit.

# /fix /allattached

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/fix/allattached/
Machine contract: https://start.kiwelaunch.com/fix/allattached/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/fix/allattached/contract.json
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

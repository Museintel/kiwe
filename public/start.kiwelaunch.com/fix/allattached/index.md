# /fix /allattached

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/allattached/
Machine contract: https://start.kiwelaunch.com/fix/allattached/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/fix/allattached/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/command-manifest.json

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

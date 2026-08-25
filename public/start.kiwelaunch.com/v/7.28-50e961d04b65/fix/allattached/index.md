# /fix /allattached

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/fix/allattached/
Machine contract: https://start.kiwelaunch.com/fix/allattached/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/fix/allattached/contract.json
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

# /fix /allattached

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/fix/allattached/
Machine contract: https://start.kiwelaunch.com/fix/allattached/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/fix/allattached/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

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

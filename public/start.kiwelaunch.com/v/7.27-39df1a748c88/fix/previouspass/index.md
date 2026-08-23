# /fix /previouspass

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/fix/previouspass/
Machine contract: https://start.kiwelaunch.com/fix/previouspass/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/fix/previouspass/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

diagnostic

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

## Requirements

- current artifacts and immediately previous audit findings if the human wants a fix

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- ERROR: KIWE_PREVIOUS_AUDIT_MISSING when no previous audit findings exist

## Forbidden

- execution
- silent aliasing
- fixing from memory
- stale files

## Final response

Do not execute. Explain that the canonical command is /fix /previousaudit and suggest /audit /allattached /allflow first when no previous audit findings exist.

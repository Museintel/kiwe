# /fix /previouspass

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/fix/previouspass/
Machine contract: https://start.kiwelaunch.com/fix/previouspass/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/fix/previouspass/contract.json
Release: 7.25-a818d0ce1642
Source hash: sha256:a818d0ce1642aed2f712ee4fd6f7a967e5d945511d1fa72f85d3c4de4660dd81

## Phase

diagnostic

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/command-manifest.json

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

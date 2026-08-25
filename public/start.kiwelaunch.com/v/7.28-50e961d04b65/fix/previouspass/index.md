# /fix /previouspass

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/fix/previouspass/
Machine contract: https://start.kiwelaunch.com/fix/previouspass/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/fix/previouspass/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

diagnostic

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/command-manifest.json

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

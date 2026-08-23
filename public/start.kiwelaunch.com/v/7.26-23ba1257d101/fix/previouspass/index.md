# /fix /previouspass

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/fix/previouspass/
Machine contract: https://start.kiwelaunch.com/fix/previouspass/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/fix/previouspass/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

diagnostic

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/command-manifest.json

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

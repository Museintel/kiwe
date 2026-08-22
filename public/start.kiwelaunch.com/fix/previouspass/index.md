# /fix /previouspass

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/previouspass/
Machine contract: https://start.kiwelaunch.com/fix/previouspass/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/fix/previouspass/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

diagnostic

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/command-manifest.json

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

# /fix /previouspass

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/previouspass/
Machine contract: https://start.kiwelaunch.com/fix/previouspass/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

diagnostic

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

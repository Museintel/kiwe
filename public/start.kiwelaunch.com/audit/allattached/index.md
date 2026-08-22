# /audit /allattached

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/allattached/
Machine contract: https://start.kiwelaunch.com/audit/allattached/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

audit

## Requirements

- one or more current attached/supplied artifacts

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- pass/fail findings for every detected lane

## Forbidden

- fixes unless /fix is also present
- creative rebuild
- redesign
- docs unless /document
- ZIP
- stale files

## Final response

Return compact PASS/FAIL/WARN per detected lane and next matching /fix command when needed.

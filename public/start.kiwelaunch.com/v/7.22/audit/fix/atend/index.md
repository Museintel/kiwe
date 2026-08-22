# /audit /fix /atend

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/fix/atend/
Machine contract: https://start.kiwelaunch.com/audit/fix/atend/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

flag

## Requirements

- /execute /fullflow

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- final audit/fix/re-audit gates before delivery

## Forbidden

- standalone generation
- skipping re-audit after a fix

## Final response

Use as an execution flag. After generation/conversion, run required closure audits, fix failed lanes, and rerun the same audits until PASS or NEEDS_INPUT.

# /audit /fix /eachstep

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/fix/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/fix/eachstep/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

flag

## Requirements

- /execute /stepbystep or /execute /fullflow

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- explicit audit/fix/re-audit loop after each phase before continuing

## Forbidden

- standalone generation
- skipping re-audit after a fix

## Final response

Use as an execution flag. For each phase: generate/convert -> audit -> fix actual artifact if failed -> rerun same audit -> repeat until PASS or NEEDS_INPUT.

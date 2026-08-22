# /audit /fix /eachstep

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/fix/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/fix/eachstep/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

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

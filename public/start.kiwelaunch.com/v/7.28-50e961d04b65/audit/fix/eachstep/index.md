# /audit /fix /eachstep

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/audit/fix/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/fix/eachstep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/audit/fix/eachstep/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/command-manifest.json

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

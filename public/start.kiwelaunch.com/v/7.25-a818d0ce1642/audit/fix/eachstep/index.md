# /audit /fix /eachstep

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/audit/fix/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/fix/eachstep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/audit/fix/eachstep/contract.json
Release: 7.25-a818d0ce1642
Source hash: sha256:a818d0ce1642aed2f712ee4fd6f7a967e5d945511d1fa72f85d3c4de4660dd81

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/command-manifest.json

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

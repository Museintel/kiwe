# /audit /fix /atend

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/audit/fix/atend/
Machine contract: https://start.kiwelaunch.com/audit/fix/atend/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/audit/fix/atend/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/command-manifest.json

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

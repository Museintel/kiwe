# /audit /eachstep

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/audit/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/eachstep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/audit/eachstep/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/command-manifest.json

## Requirements

- /execute /stepbystep or /execute /fullflow

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- audit/fix gates after each phase before continuing

## Forbidden

- standalone generation

## Final response

Use as an execution flag; do not treat as a standalone artifact command.

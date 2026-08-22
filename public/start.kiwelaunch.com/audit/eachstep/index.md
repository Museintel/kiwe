# /audit /eachstep

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/eachstep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/audit/eachstep/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/command-manifest.json

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

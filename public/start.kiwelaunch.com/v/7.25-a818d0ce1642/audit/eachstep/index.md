# /audit /eachstep

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/audit/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/eachstep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/audit/eachstep/contract.json
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

- audit/fix gates after each phase before continuing

## Forbidden

- standalone generation

## Final response

Use as an execution flag; do not treat as a standalone artifact command.

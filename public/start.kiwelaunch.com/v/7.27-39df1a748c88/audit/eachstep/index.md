# /audit /eachstep

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/audit/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/eachstep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/audit/eachstep/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

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

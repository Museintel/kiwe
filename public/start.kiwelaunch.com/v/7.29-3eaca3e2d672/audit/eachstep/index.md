# /audit /eachstep

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/audit/eachstep/
Machine contract: https://start.kiwelaunch.com/audit/eachstep/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/audit/eachstep/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/command-manifest.json

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

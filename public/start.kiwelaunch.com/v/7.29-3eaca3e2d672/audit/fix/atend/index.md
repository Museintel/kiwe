# /audit /fix /atend

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/audit/fix/atend/
Machine contract: https://start.kiwelaunch.com/audit/fix/atend/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/audit/fix/atend/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/command-manifest.json

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

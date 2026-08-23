# /audit /fix /atend

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/audit/fix/atend/
Machine contract: https://start.kiwelaunch.com/audit/fix/atend/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/audit/fix/atend/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

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

# /audit /allattached

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/audit/allattached/
Machine contract: https://start.kiwelaunch.com/audit/allattached/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/audit/allattached/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

## Requirements

- one or more current attached/supplied artifacts

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- pass/fail findings for every detected lane

## Forbidden

- fixes unless /fix is also present
- creative rebuild
- redesign
- docs unless /document
- ZIP
- stale files

## Final response

Return compact PASS/FAIL/WARN per detected lane and next matching /fix command when needed.

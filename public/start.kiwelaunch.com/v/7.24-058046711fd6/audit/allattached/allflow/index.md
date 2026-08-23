# /audit /allattached /allflow

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/audit/allattached/allflow/
Machine contract: https://start.kiwelaunch.com/audit/allattached/allflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/audit/allattached/allflow/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/command-manifest.json

## Requirements

- current attached/supplied artifacts or file map

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- pass/fail findings per detected artifact lane and per required closure audit

## Forbidden

- fixes unless /fix is also present
- creative rebuild
- redesign
- docs unless /document
- ZIP
- stale files

## Final response

Return compact PASS/FAIL/WARN for every detected artifact and every required closure audit.

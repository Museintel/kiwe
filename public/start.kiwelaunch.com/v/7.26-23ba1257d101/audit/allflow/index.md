# /audit /allflow

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/audit/allflow/
Machine contract: https://start.kiwelaunch.com/audit/allflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/audit/allflow/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/command-manifest.json

## Requirements

- classified current artifact or file map

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- pass/fail findings for every required closure audit

## Forbidden

- fixes unless /fix is also present
- creative rebuild
- redesign
- docs unless /document
- ZIP
- stale files

## Final response

Return compact PASS/FAIL/WARN across all required closure audits for the detected flow.

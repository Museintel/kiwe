# /audit /allflow

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/audit/allflow/
Machine contract: https://start.kiwelaunch.com/audit/allflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/audit/allflow/contract.json
Release: 7.25-a818d0ce1642
Source hash: sha256:a818d0ce1642aed2f712ee4fd6f7a967e5d945511d1fa72f85d3c4de4660dd81

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/command-manifest.json

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

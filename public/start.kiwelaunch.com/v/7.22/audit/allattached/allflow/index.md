# /audit /allattached /allflow

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/allattached/allflow/
Machine contract: https://start.kiwelaunch.com/audit/allattached/allflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/audit/allattached/allflow/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/command-manifest.json

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

# /audit /allattached /allflow

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/audit/allattached/allflow/
Machine contract: https://start.kiwelaunch.com/audit/allattached/allflow/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/audit/allattached/allflow/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/command-manifest.json

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

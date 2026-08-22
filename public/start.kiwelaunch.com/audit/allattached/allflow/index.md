# /audit /allattached /allflow

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/allattached/allflow/
Machine contract: https://start.kiwelaunch.com/audit/allattached/allflow/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

audit

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

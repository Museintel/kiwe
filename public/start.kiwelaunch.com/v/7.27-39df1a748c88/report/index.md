# /report

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/report/
Machine contract: https://start.kiwelaunch.com/report/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/report/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/command-manifest.json

## Requirements

- /execute /stepbystep or /execute /fullflow

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- compact generated/audited/fixed/warnings report

## Forbidden

- creating documentation files
- long prose
- extra reports unless /document is also present

## Final response

In /execute /stepbystep, stop after the current phase closes, return the current phase file and compact report, then wait for human continue. In /execute /fullflow, include a compact final phase ledger only.

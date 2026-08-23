# /report

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/report/
Machine contract: https://start.kiwelaunch.com/report/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/report/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/command-manifest.json

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

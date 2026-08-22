# /report

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/report/
Machine contract: https://start.kiwelaunch.com/report/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/report/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/command-manifest.json

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

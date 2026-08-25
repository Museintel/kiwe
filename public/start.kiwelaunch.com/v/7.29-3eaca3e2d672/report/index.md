# /report

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/report/
Machine contract: https://start.kiwelaunch.com/report/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/report/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

flag

## Hosted resources

- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/command-manifest.json

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

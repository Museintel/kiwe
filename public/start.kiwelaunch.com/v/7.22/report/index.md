# /report

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/report/
Machine contract: https://start.kiwelaunch.com/report/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

flag

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

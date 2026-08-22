# /audit /allflow

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/allflow/
Machine contract: https://start.kiwelaunch.com/audit/allflow/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

audit

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

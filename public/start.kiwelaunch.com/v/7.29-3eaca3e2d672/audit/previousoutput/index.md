# /audit /previousoutput

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/audit/previousoutput/
Machine contract: https://start.kiwelaunch.com/audit/previousoutput/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/audit/previousoutput/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/command-manifest.json

## Requirements

- files generated in the immediate previous AI output in this same session

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- pass/fail findings for every detected lane in the previous output

## Forbidden

- fixes unless /fix is also present
- creative rebuild
- redesign
- docs unless /document
- ZIP
- stale files
- searching downloads or old conversations

## Final response

Audit only the immediate previous AI output files. If unavailable, stop with ERROR: KIWE_PREVIOUS_OUTPUT_MISSING.

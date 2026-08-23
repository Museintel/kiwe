# /audit /previousoutput

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/audit/previousoutput/
Machine contract: https://start.kiwelaunch.com/audit/previousoutput/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/audit/previousoutput/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/entry.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/entry.json
- https://start.kiwelaunch.com/command-manifest.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/command-manifest.json

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

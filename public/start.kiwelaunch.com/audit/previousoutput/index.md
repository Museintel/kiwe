# /audit /previousoutput

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/previousoutput/
Machine contract: https://start.kiwelaunch.com/audit/previousoutput/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

audit

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

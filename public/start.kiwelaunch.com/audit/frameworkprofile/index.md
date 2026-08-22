# /audit /frameworkprofile

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/audit/frameworkprofile/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

audit

## Requirements

- framework/kiwe-framework-profile.json

## Required behavior

- reject custom token names in overrides
- allow project-specific variables/classes only in settings.tokens.project
- verify project variable/class names are prefixed and collision-safe

## Outputs

- pass/fail findings only

## Forbidden

- fixes unless /fix is also present
- docs unless /document
- ZIP

## Final response

PASS/FAIL with blocking errors and warnings.

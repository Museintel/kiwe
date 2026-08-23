# /audit /frameworkprofile

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/audit/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/audit/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/audit/frameworkprofile/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/contexts/workflow-lite.md

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

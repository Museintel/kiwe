# /audit /frameworkprofile

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/audit/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/audit/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/audit/frameworkprofile/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contexts/workflow-lite.md

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

# /audit /frameworkprofile

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/audit/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/audit/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/audit/frameworkprofile/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/workflow-lite.md

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

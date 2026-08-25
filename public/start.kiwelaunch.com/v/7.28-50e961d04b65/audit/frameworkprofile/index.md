# /audit /frameworkprofile

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/audit/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/audit/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/audit/frameworkprofile/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/contexts/workflow-lite.md

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

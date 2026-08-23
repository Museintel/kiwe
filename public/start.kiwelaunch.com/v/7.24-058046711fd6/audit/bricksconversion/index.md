# /audit /bricksconversion

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/audit/bricksconversion/
Machine contract: https://start.kiwelaunch.com/audit/bricksconversion/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/audit/bricksconversion/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/contracts/seam-compiler-contract.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contracts/seam-compiler-contract.json
- https://start.kiwelaunch.com/contexts/seam-compiler-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contexts/seam-compiler-lite.md
- https://start.kiwelaunch.com/contexts/bricks-conversion-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contexts/bricks-conversion-lite.md

## Requirements

- raw source plus native Bricks template upload JSON or compiler proof bundle

## Required behavior

- run executable structural/native-coverage validation
- treat the source as the visual contract even when it contains its own responsive defect
- require equal viewport provenance before any visual percentage
- mark mismatched canvas, DPR, page identity, scrollbar geometry, stale target CSS, or foreign overlay contamination INCOMPLETE
- report native coverage and scoped CSS exceptions separately
- fail runtime Code elements unless explicitly marked review-only unsupported exceptions
- fail framework-dependent settings in raw mode

## Outputs

- pass/fail findings only

## Forbidden

- fixes unless /fix is also present
- docs unless /document
- ZIP

## Final response

PASS/FAIL/INCOMPLETE with executable proof, valid visual score when available, blocking errors, and warnings.

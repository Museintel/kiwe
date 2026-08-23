# /audit /bricksconversion

SeamFlow contract: 7.25

Canonical URL: https://start.kiwelaunch.com/audit/bricksconversion/
Machine contract: https://start.kiwelaunch.com/audit/bricksconversion/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/audit/bricksconversion/contract.json
Release: 7.25-a818d0ce1642
Source hash: sha256:a818d0ce1642aed2f712ee4fd6f7a967e5d945511d1fa72f85d3c4de4660dd81

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/contracts/seam-compiler-contract.json
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/contracts/seam-compiler-contract.json
- https://start.kiwelaunch.com/contexts/seam-compiler-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/contexts/seam-compiler-lite.md
- https://start.kiwelaunch.com/contexts/bricks-conversion-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.25-a818d0ce1642/contexts/bricks-conversion-lite.md

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

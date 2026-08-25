# /audit /seamframework

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/audit/seamframework/
Machine contract: https://start.kiwelaunch.com/audit/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/audit/seamframework/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

audit

## Hosted resources

- https://start.kiwelaunch.com/contracts/seam-compiler-contract.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contracts/seam-compiler-contract.json
- https://start.kiwelaunch.com/contexts/seam-compiler-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/seam-compiler-lite.md
- https://start.kiwelaunch.com/contexts/bricks-conversion-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/bricks-conversion-lite.md

## Requirements

- Framework Profile plus every dependent Bricks template

## Required behavior

- execute validate-seamframework.cjs or the SEAM Compiler package audit; never reuse the older raw-page adoption audit for a Framework package
- require status PASS and integration 100
- verify profile-before-template install order
- verify every consumed variable and reusable class is defined by the profile
- verify profile class IDs exactly match every dependent template _cssGlobalClasses reference
- fail duplicate Theme Style/project-class/element visual ownership
- fail local H1-H6 locks that should inherit Bricks Theme Style
- compare the raw and Framework templates to prove no redesign
- include validator proof in any PASS response

## Outputs

- framework/audit-seamframework.json
- pass/fail findings only

## Forbidden

- manual-only PASS
- copied/reconstructed validator PASS
- fixes unless /fix is also present
- docs unless /document
- ZIP

## Final response

PASS/FAIL/INCOMPLETE with package proof, blocking errors, warnings, and install order.

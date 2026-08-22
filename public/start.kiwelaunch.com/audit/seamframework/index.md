# /audit /seamframework

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/audit/seamframework/
Machine contract: https://start.kiwelaunch.com/audit/seamframework/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

audit

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

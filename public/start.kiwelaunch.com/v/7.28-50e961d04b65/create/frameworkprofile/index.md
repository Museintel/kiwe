# /create /frameworkprofile

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/create/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/create/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/create/frameworkprofile/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

create

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/contexts/workflow-lite.md

## Requirements

- explicit approved foundation tokens for a new blank profile, or deterministic SEAM Compiler evidence for a converted project

## Required behavior

- for converted HTML/CSS/JS use /convert /bricks /seamframework; do not infer an importable profile from screenshots, prose, or serialized Bricks alone
- run the official framework-profile generator and validate-framework-profile.cjs, Kiwe MCP, or Kiwe REST validator before PASS
- official Kiwe universal values go in settings.tokens.overrides
- project-specific variables/classes required by Bricks templates go in settings.tokens.project and are pushed to dedicated Kiwe Project categories in Bricks

## Outputs

- framework/kiwe-framework-profile.json

## Forbidden

- browser-AI-authored production profile inferred from a converted webpage
- manual-only PASS
- Bricks template JSON
- DSA theme package
- docs unless /document
- ZIP

## Final response

Return the deterministically generated profile and executable validation status; otherwise return WARN/UNVERIFIED and route converted projects to /convert /bricks /seamframework.

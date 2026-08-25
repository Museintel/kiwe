# /create /frameworkprofile

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/create/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/create/frameworkprofile/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/create/frameworkprofile/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

create

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/workflow-lite.md

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

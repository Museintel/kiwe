# /create /frameworkprofile

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/create/frameworkprofile/
Machine contract: https://start.kiwelaunch.com/create/frameworkprofile/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

create

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

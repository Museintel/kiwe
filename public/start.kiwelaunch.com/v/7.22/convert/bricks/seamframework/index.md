# /convert /bricks /seamframework

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/convert/bricks/seamframework/
Machine contract: https://start.kiwelaunch.com/convert/bricks/seamframework/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

framework

## Requirements

- arbitrary HTML/CSS/JS project, folder, archive, or standalone document

## Required behavior

- run SEAM Compiler 0.13.0 in one-pass Framework mode
- capture raw rendered evidence before Framework ownership
- emit one project-wide Framework Profile before dependent templates
- emit an explicit install-order manifest
- run /audit /seamframework and require PASS at 100% integration

## Outputs

- framework/kiwe-framework-profile.json
- framework/audit-seamframework.json
- manifest-seam-framework.json
- bricks-template/[name]-template-upload.json

## Forbidden

- source redesign
- AI-authored production JSON
- inline variable fallbacks
- discarding raw compiler evidence before Framework mapping
- docs unless /document
- ZIP

## Final response

Return the audited one-pass package with profile-first install order.

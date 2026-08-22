# /convert /bricks /seamframework

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/convert/bricks/seamframework/
Machine contract: https://start.kiwelaunch.com/convert/bricks/seamframework/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

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

# /convert /bricks /seamframework

SeamFlow contract: 7.26

Canonical URL: https://start.kiwelaunch.com/convert/bricks/seamframework/
Machine contract: https://start.kiwelaunch.com/convert/bricks/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.26-23ba1257d101/convert/bricks/seamframework/contract.json
Release: 7.26-23ba1257d101
Source hash: sha256:23ba1257d1015aa0e9d28526dcab493a6dd6b6eba6cd2165024b7f6421dbd36d

## Phase

framework

## Hosted resources

- https://start.kiwelaunch.com/contracts/seam-compiler-contract.json
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/contracts/seam-compiler-contract.json
- https://start.kiwelaunch.com/contexts/seam-compiler-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/contexts/seam-compiler-lite.md
- https://start.kiwelaunch.com/contexts/bricks-conversion-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.26-23ba1257d101/contexts/bricks-conversion-lite.md

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

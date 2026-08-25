# /convert /bricks /seamframework

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/convert/bricks/seamframework/
Machine contract: https://start.kiwelaunch.com/convert/bricks/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/convert/bricks/seamframework/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

framework

## Hosted resources

- https://start.kiwelaunch.com/contracts/seam-compiler-contract.json
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contracts/seam-compiler-contract.json
- https://start.kiwelaunch.com/contexts/seam-compiler-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/seam-compiler-lite.md
- https://start.kiwelaunch.com/contexts/bricks-conversion-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/bricks-conversion-lite.md

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

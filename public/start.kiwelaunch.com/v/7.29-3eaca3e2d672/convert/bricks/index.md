# /convert /bricks

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/convert/bricks/
Machine contract: https://start.kiwelaunch.com/convert/bricks/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/convert/bricks/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

convert

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

- run SEAM Compiler 0.13.0 or a compatible deterministic compiler endpoint; browser AI must not author production JSON
- keep raw conversion Framework-neutral
- discover arbitrary pages without home/shop route assumptions
- split a complete standalone document into header, footer and content templates when applicable
- preserve source defects as 1:1 parity instead of redesigning them
- use Bricks-native element controls before scoped CSS
- preserve authored classes, attributes, interactions and executable behavior only within the compiler safety contract
- when live Bricks proof is used, reject stale page CSS, mismatched viewport provenance and foreign overlay contamination before scoring

## Outputs

- bricks-template/[page-or-template-name]-template-upload.json

## Forbidden

- mandatory Framework Profile
- automatic Seam token/class injection
- AI-authored production JSON
- project-specific compiler rules
- Code element fallback when native Bricks elements are possible
- docs unless /document
- ZIP

## Final response

Return every discovered raw native Bricks template and compact executable conversion proof.

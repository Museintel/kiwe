# /convert /bricks

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/convert/bricks/
Machine contract: https://start.kiwelaunch.com/convert/bricks/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

convert

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

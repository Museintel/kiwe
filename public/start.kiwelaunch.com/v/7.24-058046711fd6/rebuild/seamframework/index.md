# /rebuild /seamframework

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/rebuild/seamframework/
Machine contract: https://start.kiwelaunch.com/rebuild/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/rebuild/seamframework/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

compatibility

## Hosted resources

- https://start.kiwelaunch.com/contracts/seam-compiler-contract.json
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contracts/seam-compiler-contract.json
- https://start.kiwelaunch.com/contexts/seam-compiler-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contexts/seam-compiler-lite.md

## Requirements

- raw HTML/CSS/JS or an existing raw conversion result

## Required behavior

- normalize this legacy command to deterministic raw /convert /bricks followed by optional /seamframework
- never manually rewrite source HTML or production Bricks JSON

## Outputs

- framework/kiwe-framework-profile.json
- bricks-template/[name]-template-upload.json

## Forbidden

- legacy regex/token substitution as production authority
- AI-authored production JSON
- source redesign
- docs unless /document
- ZIP

## Final response

Report that the legacy command was normalized to Convert then Framework and return executable proof.

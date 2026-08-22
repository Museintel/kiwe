# /rebuild /seamframework

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/rebuild/seamframework/
Machine contract: https://start.kiwelaunch.com/rebuild/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.22/rebuild/seamframework/contract.json
Source hash: sha256:132bf1a5aa3485fb3326739ec749f9f45166927bb2054501b4307573dc7091f3

## Phase

compatibility

## Hosted resources

- https://start.kiwelaunch.com/contracts/seam-compiler-contract.json
  - Pinned: https://start.kiwelaunch.com/v/7.22/contracts/seam-compiler-contract.json
- https://start.kiwelaunch.com/contexts/seam-compiler-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.22/contexts/seam-compiler-lite.md

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

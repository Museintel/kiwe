# /create /designcontextenhancement

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/create/designcontextenhancement/
Machine contract: https://start.kiwelaunch.com/create/designcontextenhancement/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/create/designcontextenhancement/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

design-context-enhancement

## Hosted resources

- https://start.kiwelaunch.com/contexts/dynamic-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/contexts/dynamic-lite.md

## Requirements

- a current kiwe.sitegraph-design-context.v1 export containing ownerContextHash and designContextEnhancementContract

## Required behavior

- copy the exact sourceContextHash
- set authority.mayOverwriteOwnerEvidence to false
- preserve every locked path and owner-selected color
- suggest colors only for semantic roles the owner left empty
- keep factual claims grounded in owner/SiteGraph evidence
- put uncertainty in requiresHumanReview

## Outputs

- kiwe-design-context-enhancement.json using schema kiwe.design-context-enhancement.v1

## Forbidden

- overwriting owner evidence
- inventing contacts addresses products legal facts awards or dates
- meta keywords
- keyword stuffing
- Bricks JSON
- automatically enabling Seam Framework
- browser-AI-authored production Framework Profile

## Final response

Return the enhancement JSON only plus compact locked/preserved/review counts. The administrator imports it in Kiwe > Framework.

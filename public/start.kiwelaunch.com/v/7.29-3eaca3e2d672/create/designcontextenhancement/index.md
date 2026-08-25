# /create /designcontextenhancement

SeamFlow contract: 7.29

Canonical URL: https://start.kiwelaunch.com/create/designcontextenhancement/
Machine contract: https://start.kiwelaunch.com/create/designcontextenhancement/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/create/designcontextenhancement/contract.json
Release: 7.29-3eaca3e2d672
Source hash: sha256:3eaca3e2d672afebb09b83b70a1f85d71e8c21631ad7b227bf132736906c67ed

## Phase

design-context-enhancement

## Hosted resources

- https://start.kiwelaunch.com/contexts/dynamic-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.29-3eaca3e2d672/contexts/dynamic-lite.md

## Requirements

- a current kiwe.site-graph.v1 export containing ownerContextHash and designContextEnhancementContract

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

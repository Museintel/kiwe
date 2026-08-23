# /create /designcontextenhancement

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/create/designcontextenhancement/
Machine contract: https://start.kiwelaunch.com/create/designcontextenhancement/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/create/designcontextenhancement/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

design-context-enhancement

## Hosted resources

- https://start.kiwelaunch.com/contexts/dynamic-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/contexts/dynamic-lite.md

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

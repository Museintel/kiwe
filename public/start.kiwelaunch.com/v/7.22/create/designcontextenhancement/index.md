# /create /designcontextenhancement

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/create/designcontextenhancement/
Machine contract: https://start.kiwelaunch.com/create/designcontextenhancement/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

design-context-enhancement

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

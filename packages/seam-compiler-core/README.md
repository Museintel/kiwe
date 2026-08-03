# SEAM Compiler Core

This package converts rendered `seam.capture.v1` evidence into normalized page, behavior, and asset IR. It contains no browser, WordPress, network, or AI client and performs no mutation.

The core is deterministic: identical capture input produces identical IR. `classify-component.cjs` records the semantic type, selected adapter class, confidence, evidence, and limitations for every source node. It distinguishes behavior-equivalent native mappings from semantic-layout preservation and explicit review boundaries. AI may later propose bounded classifications against an IR slice, but `ai-direct-json.cjs` permanently rejects AI-authored Bricks JSON as compiler input.

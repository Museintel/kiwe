# SEAM Bricks Adapter

`metrics.nativeCoverage` is an evidence ratio: serialized native Bricks controls divided by serialized controls plus unresolved source declarations. It is deliberately not an element-count vanity metric and must not be interpreted as visual parity; the visual-proof plane owns that claim.

This package owns the only supported `seam.bricks-plan.v1` to native Bricks serialization path.

- `tools/extract-bricks-capabilities.cjs` reads an installed Bricks source tree and emits a deterministic capability profile without loading WordPress or executing Bricks PHP.
- `lib/compile-plan.cjs` maps normalized SEAM page nodes to controls proven by that profile and emits an explicit single-owner ledger.
- `lib/component-adapters.cjs` owns deterministic aggregate adapters for plain lists, media, and sanitized inline SVG while preserving every consumed capture-node ID as provenance.
- `lib/serialize-template.cjs` rejects anything except a schema-valid deterministic SEAM plan.
- `scaffold/legacy-browser-converter.ts` preserves the former browser converter for regression archaeology only. It is excluded from the supported compiler path.

Navigation and tables keep semantic tags on native layout elements when a specialized Bricks widget would invent behavior. Arbitrary form submission, unknown embeds, and unsanitized vectors remain review boundaries rather than being silently changed. No AI output can enter at the plan or template boundary.

Every compiled native element carries `data-seam-proof-node` through Bricks' native custom-attributes control. The visual-proof plane uses that stable Page IR provenance to compare staged Bricks geometry and semantics without DOM-position guessing or custom runtime JavaScript.

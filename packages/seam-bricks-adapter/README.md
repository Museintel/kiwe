# SEAM Bricks Adapter

`metrics.nativeCoverage` is an evidence ratio: serialized native Bricks controls divided by serialized controls plus unresolved source declarations. It is deliberately not an element-count vanity metric and must not be interpreted as visual parity; the visual-proof plane owns that claim.

This package owns the only supported `seam.bricks-plan.v1` to native Bricks serialization path.

- `tools/extract-bricks-capabilities.cjs` reads an installed Bricks source tree and emits a deterministic capability profile without loading WordPress or executing Bricks PHP.
- `lib/compile-plan.cjs` maps normalized SEAM page nodes to controls proven by that profile.
- `lib/serialize-template.cjs` rejects anything except a schema-valid deterministic SEAM plan.
- `scaffold/legacy-browser-converter.ts` preserves the former browser converter for regression archaeology only. It is excluded from the supported compiler path.

No AI output can enter at the plan or template boundary.

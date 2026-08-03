# SEAM Visual Proof

This package compares a controlled reference capture with a staged Bricks capture. It evaluates PNG pixels, source-to-Bricks anchor boxes, selected computed styles, semantic roles/names, and new capture diagnostics for every matching viewport/theme/state.

Compiled Bricks elements carry a native `data-seam-proof-node` attribute. Candidate capture nodes use that attribute to recover source provenance even though Bricks changes the DOM structure and element IDs.

```text
node packages/seam-visual-proof/tools/compare-proof.cjs reference/seam-capture.json staged/seam-capture.json proof-output --bricks-plan appsite/bricks/bricks-plan.json
node packages/seam-visual-proof/tools/attach-proof.cjs appsite-output proof-output/report.json proof-output/repair-plan.json
```

`matrix-exact` and `matrix-high` apply only to the declared capture matrix. They are not universal fidelity claims. A missing screenshot, viewport, or trustworthy anchor blocks proof. Repair output is proposal-only and must be implemented through canonical IR/compiler rules followed by recompilation; it never patches generated Bricks JSON.

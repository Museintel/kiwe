# SEAM Compiler R11 — DOM and Bricks boundary contract — 2026-08-09

## Scope

R11 is a general compiler repair batch. It contains no National Chikki text, selectors, class names, colors, assets, or geometry rules. National Chikki remains regression evidence only.

## Implemented contracts

### Mixed inline content

Safe formatting-only subtrees such as `<span><b>1922</b> Lonavala heritage</span>` compile into one editable native Bricks Basic Text element. Inline markup remains inside the element instead of becoming additional Bricks wrapper elements.

Descendant source proof identities are preserved inside the native text value so geometry and selector evidence remain addressable after import.

### Selector stability

Authored type and universal selectors are anchored to `data-seam-proof-node`. A source selector such as `.badge span` becomes `.badge span[data-seam-proof-node]` in the Bricks selector model.

Compiler-created wrappers do not carry source proof identities, so they cannot unexpectedly acquire source borders, padding, backgrounds, radii, typography, or interaction affordances. This prevents selector cardinality from increasing solely because Bricks requires an element around a source text node.

### Native sticky Header ownership

When a top-level project Header root is authored as sticky, the exported Bricks Header template includes:

```json
{
  "templateSettings": {
    "headerSticky": true
  }
}
```

Bricks 2.3.10 applies this setting to `#brx-header` through its native `brx-sticky` behavior. The compiler removes redundant inner root `position` and `top` settings so the Bricks template boundary, rather than a constrained descendant, owns scrolling.

### Bricks capability registry

The compiler now carries a versioned inventory of all 86 element modules found in Bricks 2.3.10:

- 11 verified native inference targets
- 1 constrained native HTML fallback
- 74 registered Bricks capabilities awaiting explicit semantic inference contracts

`registered` does not imply automatic conversion support. This distinction prevents capability totals from being presented as unsupported 100% coverage claims.

## Output and evidence

- Compiler schema: `2.5.0`
- Global-class revision: `dom-stability-v5`
- Product version: `0.7.0`
- Automated build/tests: 10 passed
- Converter report additions: inline subtrees preserved, selectors stabilized, and inferred/total Bricks capability count

## Gate

R11 code correctness passes. Visual fidelity is not approved until fresh R11 templates are imported and the same 12-state Home/About/Contact/Shop comparison is rerun. SiteGraph remains deferred until that proof gate passes.

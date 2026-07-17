# Trusted Bricks apply plan

Batch 3 adds the planning layer between a validated `bricks-bindings/kiwe-bindings.json` file and any future Bricks mutation adapter.

This layer is intentionally non-mutating.

## What it produces

```text
bricks-apply/
  kiwe-apply-plan.json
  APPLY-NOTES.md
```

The plan uses schema:

```text
kiwe.bricks-apply-plan.v1
```

It records:

- the validated binding plan and target Site Graph paths;
- Bricks/WP capability availability from the Site Graph;
- dry-run safety flags;
- preflight gates;
- query-loop operations;
- dynamic-data operations;
- Kiwe launcher operations;
- Kiwe Menu context operations;
- manual review items;
- the required apply sequence.

## What it does not do

It does not:

- save Bricks data;
- publish a page;
- create products/posts/categories;
- run checkout/cart/search/auth code;
- create a service worker;
- claim WordPress was changed.

The plan is a bridge contract for a future trusted adapter. A browser AI handoff is not that adapter.

## Commands

Validate the binding plan first:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs ./path/to/handoff --site-graph ./site-graph.json
```

Prepare a dry-run apply plan:

```bash
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json
```

Optionally write the plan into the handoff:

```bash
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json --write
```

MCP clients can call:

```text
kiwe_prepare_apply_plan
```

## Future adapter rules

A future adapter may use Bricks 2.4 abilities or Bricks builder import workflows only after:

1. `validate-bindings` passes;
2. `prepare-apply-plan` passes;
3. the admin explicitly approves the target page/site;
4. a rollback/revision point exists;
5. the adapter can inspect the rendered Bricks tree before save;
6. post-apply Kiwe audit and browser smoke tests pass.

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

Admins can download `site-graph.json` from `Kiwe > Framework > AI connector and Site Graph`.
Admins can also upload an AI-produced `kiwe-bindings.json` there to run the same style of validation against the current live Site Graph without mutating Bricks. After upload, Kiwe also renders the dry-run apply-plan preview in admin so non-developers can inspect the same preflight gates and planned operations without running the CLI. The report includes a nonce-protected download for the reviewed apply-plan JSON and a staging action for trusted adapter review.

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

## Staging record

The WordPress admin can stage a validated dry-run apply plan as:

```text
kiwe.trusted-apply-stage.v1
```

The stage record stores:

- a stable stage id;
- the apply-plan hash;
- source site/file context;
- counts for operations, preflight gates, and manual review items;
- gate statuses and blockers;
- future apply requirements;
- the reviewed dry-run plan.

The staging queue is capped and Kiwe-owned. It is a review queue for a future trusted adapter, not a builder mutation.

## Adapter proof

Batch 9 adds a proof layer for staged candidates:

```text
kiwe.trusted-adapter-proof.v1
```

The proof is generated from:

1. the staged `kiwe.trusted-apply-stage.v1` record;
2. the current live `kiwe.site-graph.v1` context.

It records:

- current Bricks/WordPress capability signals;
- stage/apply-plan gates;
- blockers;
- operation mapping for future adapter review;
- future apply requirements.

The proof does not call Bricks save APIs, does not update WordPress pages, does not publish content, and does not modify WooCommerce data. It exists so a future apply button can refuse unsafe/stale candidates before any mutation path exists.

The WordPress admin preview/download/stage/proof flow and the CLI/MCP planner share the same authority boundary: they are planning artifacts only. They do not become a trusted adapter and they do not prove a page was saved.

## Future adapter rules

A future adapter may use Bricks 2.4 abilities or Bricks builder import workflows only after:

1. `validate-bindings` passes;
2. `prepare-apply-plan` passes;
3. the admin explicitly approves the target page/site;
4. a rollback/revision point exists;
5. the adapter can inspect the rendered Bricks tree before save;
6. post-apply Kiwe audit and browser smoke tests pass.

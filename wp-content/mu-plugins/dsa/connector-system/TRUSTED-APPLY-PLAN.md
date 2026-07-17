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

## Guarded authorization

Batch 10 adds a future-apply authorization token:

```text
kiwe.guarded-apply-authorization.v1
```

The authorization can be attached only after:

1. a valid stage exists;
2. the stage has no blockers;
3. a trusted-adapter proof exists;
4. the proof is `adapter-proof-ready`;
5. the proof has no blockers.

It records the admin user, stage id, plan hash, gates, blockers, and future-only authority. It is not the final apply button. It does not call Bricks save APIs, does not update WordPress pages, does not publish content, and does not modify WooCommerce data.

The WordPress admin preview/download/stage/proof/authorization flow and the CLI/MCP planner share the same authority boundary: they are planning artifacts only. They do not become a trusted adapter and they do not prove a page was saved.

## Pre-execution gate

Batch 11 adds the last non-mutating checkpoint before a future adapter is allowed to exist:

```text
kiwe.pre-execution-gate.v1
```

The gate can be attached only after:

1. a valid stage exists;
2. the reviewed dry-run apply plan is present;
3. a trusted-adapter proof exists and is `adapter-proof-ready`;
4. guarded authorization exists and is `authorized-for-future-adapter`;
5. stage, proof, authorization, and apply-plan hashes agree;
6. no blockers remain.

It records the required future-adapter contract:

- revalidate the stage hash;
- revalidate the authorization;
- capture rollback or revision state;
- render and inspect the output before save;
- ask final admin confirmation;
- execute the smallest possible adapter mutation;
- run post-apply Kiwe audit;
- run post-apply browser smoke tests.

The gate still does not call Bricks save APIs, update WordPress pages, publish content, or modify WooCommerce data. It is a lock, not the key.

## Trusted execution preview

Batch 12 adds the rehearsal artifact:

```text
kiwe.trusted-execution-preview.v1
```

The preview can be attached only after:

1. a valid stage exists;
2. the dry-run apply plan is still present;
3. trusted-adapter proof is ready;
4. guarded authorization is ready;
5. the pre-execution gate is ready;
6. stage, authorization, and gate plan hashes match;
7. no blockers remain.

It records:

- rollback/revision requirements;
- rendered-preview requirements;
- operation-level preview requirements;
- final confirmation requirements;
- post-apply audit requirements;
- forbidden mutation actions for the current batch.

The preview still does not call Bricks save APIs, update WordPress pages, publish content, or modify WooCommerce data. It is a rehearsal for a future executor, not the executor.

## Final apply confirmation

Batch 13 adds the explicit human confirmation lock:

```text
kiwe.final-apply-confirmation.v1
```

The confirmation can be attached only after:

1. a valid stage exists;
2. the dry-run apply plan is still present;
3. trusted-adapter proof is ready;
4. guarded authorization is ready;
5. the pre-execution gate is ready;
6. the trusted execution preview is ready;
7. stage, authorization, gate, and preview plan hashes match;
8. the admin explicitly checks the exact-preview confirmation box;
9. no blockers remain.

It records:

- the confirmed stage id;
- the confirmed plan hash;
- the confirmed execution preview id;
- operation count;
- rollback, rendered-preview, and post-apply audit scope;
- the future adapter contract.

The confirmation still does not call Bricks save APIs, update WordPress pages, publish content, or modify WooCommerce data. It only says that a future mutation adapter may now be built against this exact preview and hash.

## Fresh Site Graph revalidation

Batch 14 adds the post-confirmation drift check:

```text
kiwe.fresh-sitegraph-revalidation.v1
```

The revalidation can be attached only after:

1. a valid stage exists;
2. the dry-run apply plan is still present;
3. final apply confirmation is attached;
4. the final confirmation plan hash matches the staged plan;
5. a fresh admin-generated `kiwe.site-graph.v1` is available.

It checks:

- Bricks availability for Bricks operations;
- current Bricks version and HTML/CSS conversion signal;
- WooCommerce active state;
- query-loop post types;
- taxonomy term IDs such as `product_cat::123`;
- dynamic tags when the fresh graph exposes them;
- Kiwe-owned launcher/menu-context operations.

The revalidation still does not call Bricks save APIs, update WordPress pages, publish content, or modify WooCommerce data. It exists to catch live-site drift after confirmation and before rollback/execution work begins.

## Rollback readiness checkpoint

Batch 15 adds the rollback-readiness lock:

```text
kiwe.rollback-readiness-checkpoint.v1
```

The checkpoint can be attached only after:

1. a valid stage exists;
2. the dry-run apply plan is still present;
3. the execution preview is ready;
4. final apply confirmation is attached;
5. fresh Site Graph revalidation is ready;
6. stage, confirmation, and fresh revalidation plan hashes match;
7. no blockers remain.

It records:

- locked hashes for the stage, apply plan, execution preview, final confirmation, and fresh revalidation;
- required rollback captures for WordPress/Bricks/Kiwe/source artifacts;
- `captureMode: readiness-only`;
- `actualRevisionCaptured: false`;
- `readyForRollbackCapture: true` when clean.

This checkpoint still does not capture a real WordPress revision, Bricks element tree snapshot, or backup. Those must happen immediately before a future mutation adapter saves anything, once the exact target page/template is resolved.

## Target resolution

Batch 16 adds the explicit target lock:

```text
kiwe.target-resolution.v1
```

The target lock can be attached only after:

1. a valid stage exists;
2. fresh Site Graph revalidation is ready;
3. rollback readiness is ready;
4. stage, fresh revalidation, and rollback readiness plan hashes match;
5. the admin supplies an explicit target post/page/template ID;
6. the target object exists and is not an attachment, revision, or navigation menu item;
7. no blockers remain.

It records:

- target post ID;
- post type;
- status;
- title;
- URL;
- locked plan hash;
- allowed future scope.

The target lock still does not call Bricks save APIs, update WordPress pages, publish content, or modify WooCommerce data. It only tells a future adapter which exact WordPress object is allowed to be touched after real rollback capture.

## Rollback capture

Batch 17 adds the locked-target snapshot:

```text
kiwe.rollback-capture.v1
```

The rollback capture can be attached only after:

1. a valid stage exists;
2. fresh Site Graph revalidation is ready;
3. rollback readiness is ready;
4. target resolution is ready;
5. stage, fresh revalidation, rollback readiness, and target resolution plan hashes match;
6. the resolved target object can be read;
7. no blockers remain.

It records:

- target post ID;
- post type;
- status;
- title;
- URL;
- current WordPress post fields;
- relevant meta keys using the `_bricks*`, `_kiwe_*`, `_dsa_*`, and `_wp_page_template` families;
- snapshot hash;
- meta hash;
- `actualRollbackSnapshotCaptured: true` when clean;
- `actualWordPressRevisionCreated: false`.

The capture writes only the Kiwe staging record. It still does not call Bricks save APIs, update WordPress page content, publish content, modify WooCommerce data, or create a native WordPress revision. It gives the next adapter rung a concrete restore payload for the exact locked target.

## Rendered target baseline inspection

Batch 18 adds the baseline inspection rung:

```text
kiwe.rendered-target-inspection.v1
```

The inspection can be attached only after:

1. a valid stage exists;
2. target resolution is ready;
3. rollback capture is ready;
4. stage, target resolution, and rollback capture plan hashes match;
5. the rollback snapshot is available;
6. no blockers remain.

It records:

- target post ID;
- baseline URL;
- post content length and hash;
- rollback snapshot hash;
- Bricks meta keys;
- decoded Bricks JSON payload count;
- estimated Bricks element-node count;
- operation selector coverage against current post content and Bricks meta;
- warnings for missing baseline selectors or missing Bricks meta.

Selector absence in the current baseline is not automatically blocking. A first import or blank target will often not contain the future handoff's selectors yet. The future adapter must still map selectors after conversion/import and before save.

The inspection writes only the Kiwe staging record. It does not call Bricks save APIs, update WordPress page content, publish content, modify WooCommerce data, or perform a browser render. Browser smoke remains a later post-apply/final preview requirement.

## Minimal adapter shell

Batch 19 adds the future adapter shell:

```text
kiwe.minimal-adapter-shell.v1
```

The shell can be attached only after:

1. a valid stage exists;
2. the dry-run apply plan is still present;
3. trusted adapter proof is ready;
4. target resolution is ready;
5. rollback capture is ready;
6. rendered target baseline inspection is ready;
7. stage, proof, target resolution, rollback capture, and rendered inspection plan hashes match;
8. no blockers remain.

It records:

- selected strategy:
  - `kiwe-runtime-only-review`;
  - `bricks-abilities-adapter-preferred`;
  - `html-css-to-bricks-import-review`;
  - `manual-builder-fallback`;
- allowed target post ID;
- locked plan hash;
- rollback capture ID;
- rendered inspection ID;
- allowed operation IDs;
- smallest safe step per operation;
- warnings from selector coverage and adapter capability gaps;
- final future-adapter contract.

The shell still does not call Bricks save APIs, update WordPress page content, publish content, modify WooCommerce data, or execute an adapter. It is the last non-mutating shape-selection artifact before a future controlled save path.

## Final save approval

Batch 20 adds the final save approval lock:

```text
kiwe.final-save-approval.v1
```

The approval can be attached only after:

1. a valid stage exists;
2. the dry-run apply plan is still present;
3. target resolution is ready;
4. rollback capture is ready;
5. rendered target baseline inspection is ready;
6. minimal adapter shell is ready;
7. stage, target resolution, rollback capture, rendered inspection, and shell plan hashes match;
8. the admin checks the explicit final save approval box;
9. no blockers remain.

It records:

- exact minimal adapter shell ID;
- rendered inspection ID;
- rollback capture ID;
- target resolution ID and target post ID;
- selected strategy ID;
- approved operation IDs;
- post-apply Kiwe audit plan;
- browser smoke plan;
- rollback verification plan;
- `actualSaveExecuted: false`;
- `mayExecuteMutationNow: false`;
- `mayBuildControlledExecutor: true` only when clean.

The approval still does not call Bricks save APIs, update WordPress page content, publish content, modify WooCommerce data, or execute an adapter. It is the human approval artifact for building the next controlled executor, not the executor itself.

## Future adapter rules

A future adapter may use Bricks 2.4 abilities or Bricks builder import workflows only after:

1. `validate-bindings` passes;
2. `prepare-apply-plan` passes;
3. the stage has trusted-adapter proof;
4. guarded authorization is attached;
5. the pre-execution gate is ready;
6. the trusted execution preview is ready;
7. final apply confirmation is attached;
8. fresh Site Graph revalidation passes;
9. rollback readiness checkpoint is attached;
10. target resolution is attached;
11. rollback capture is attached for the exact target;
12. rendered target baseline inspection is attached;
13. minimal adapter shell is attached;
14. final save approval is captured for the exact shell;
15. a controlled executor is built for that exact approval;
16. post-apply Kiwe audit and browser smoke tests pass.

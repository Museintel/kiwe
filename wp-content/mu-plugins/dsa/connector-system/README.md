# Kiwe Connector System

The Connector System is Kiwe's AI/data binding brain.

It sits beside the two existing brains:

- `ui-system/` — Kiwe DSA/AppShell theme contracts and previews.
- `framework-system/` — Seam Framework, tokens, vocabulary, and website/page handoff contracts.
- `connector-system/` — live WordPress/Bricks/WooCommerce/Kiwe context and dynamic binding rules.

The first connector is the **Kiwe Site Graph**.

## What the Site Graph does

The Site Graph gives an AI or developer a bounded, admin-only snapshot of what the current WordPress site can actually use:

- public post types, sample pages/posts/products, and taxonomies;
- real term IDs and slugs for Bricks query-loop filters;
- WooCommerce shop/cart/checkout/account page assignments;
- WooCommerce product categories/tags and store public address context;
- Bricks presence, version, query loop types, dynamic data tags, and Kiwe dynamic tags when available;
- Kiwe AppShell modules, dock/search settings, and launcher capabilities;
- authority boundaries so the AI does not recreate cart/search/auth/checkout/runtime behavior.

The Site Graph is read-only. It never exposes secrets, API keys, visitor state, orders, payment data, private customer data, or credentials.

## Runtime surfaces

When Kiwe is installed, admins can fetch the Site Graph from:

```text
GET /wp-json/dsa/v1/site-graph?sampleLimit=8
```

Admins can also download the same graph from:

```text
Kiwe > Framework > AI connector and Site Graph > Download Site Graph JSON
```

The admin download path is useful for browser-based AI workflows where REST cookie/nonces or external connectors are inconvenient.

Permissions:

- requires `manage_options`;
- response is `private, no-store`;
- intended for local/admin design workflows, not public crawling.

On WordPress 7+ when the Abilities API is available, Kiwe also registers:

```text
dsa/get-site-graph
dsa/validate-bindings
dsa/prepare-apply-plan
dsa/stage-apply-plan
```

These abilities give MCP/AI clients the same safe connector path as the admin and CLI tools:

- `dsa/get-site-graph` returns the current `kiwe.site-graph.v1` context.
- `dsa/validate-bindings` validates a `kiwe.bricks-bindings.v1` object against the current or supplied Site Graph.
- `dsa/prepare-apply-plan` returns a non-mutating `kiwe.bricks-apply-plan.v1` dry-run result after validation passes.
- `dsa/stage-apply-plan` stores a reviewed dry-run plan as a Kiwe internal `kiwe.trusted-apply-stage.v1` review candidate. It writes only Kiwe staging metadata; it does not save Bricks/page content.

## How this fits the AI workflow

The current successful Kiwe generation loop is:

1. AI designs a Seam website/page.
2. AI designs a Kiwe DSA/AppShell theme.
3. AI audits the handoff against the Kiwe toolkit.

The new dynamic pass becomes:

4. AI reads the Site Graph.
5. AI revises the website/page into a Bricks binding plan:
   - placeholder product/category/post rails become Bricks query-loop intentions;
   - static titles/images/prices/excerpts become Bricks dynamic-data bindings;
   - page/header buttons use canonical Kiwe launchers;
   - WooCommerce/cart/checkout/search/auth remain owned by Woo, Bricks, WordPress, and Kiwe.
6. Kiwe/Bricks validate the binding plan before anything is applied. The public toolkit validator is:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_validate_bindings`.
WordPress 7+/MCP Adapter clients can call `dsa/validate-bindings`.

Admins can also validate an AI-produced `bricks-bindings/kiwe-bindings.json` directly against the live target site from:

```text
Kiwe > Framework > AI connector and Site Graph > Validate AI binding plan
```

That admin intake is non-mutating. It reports failures/warnings and now also shows the dry-run apply-plan preview for the same upload: preflight gates, prepared Bricks query/dynamic-field operations, Kiwe launcher/menu-context operations, and manual-review items. Admins can download that reviewed `kiwe.bricks-apply-plan.v1` JSON from the report or stage it as a capped Kiwe-owned `kiwe.trusted-apply-stage.v1` review candidate. It still does not save WordPress, WooCommerce, or Bricks content.

7. Prepare a dry-run trusted apply plan:

```bash
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json
```

MCP clients can call `kiwe_prepare_apply_plan`.
WordPress 7+/MCP Adapter clients can call `dsa/prepare-apply-plan`, then `dsa/stage-apply-plan` when the human wants the reviewed plan stored in Kiwe's internal staging queue.

8. Admins may stage a reviewed plan inside Kiwe. A stage record stores the plan hash, status, gate results, blockers, counts, and future apply requirements. It is an internal review queue item, not a Bricks save.
9. Admins can run the trusted-adapter proof against a staged plan. The proof uses the current live Site Graph to verify Bricks/adapter capability signals, map operations, surface blockers, and attach `kiwe.trusted-adapter-proof.v1` metadata to the stage. It still does not save Bricks/page content.
10. Admins can authorize a proven stage for a future trusted adapter. The authorization is `kiwe.guarded-apply-authorization.v1`: an internal review token that records human approval, plan hash, gates, blockers, and future-only authority. It still does not save Bricks/page content.
11. Admins can build the pre-execution gate for an authorized stage. The gate is `kiwe.pre-execution-gate.v1`: the final non-mutating checkpoint that revalidates stage/proof/authorization hashes and records what a future adapter must do before the first mutation.
12. Admins can build a trusted execution preview for a gated stage. The preview is `kiwe.trusted-execution-preview.v1`: a rehearsal artifact that maps operations to rollback, rendered-preview, final-confirmation, and post-apply audit requirements without saving anything.
13. Admins can record final confirmation for the exact execution preview. The confirmation is `kiwe.final-apply-confirmation.v1`: an explicit checkbox-gated human approval artifact that still does not save anything.
14. Admins can revalidate the confirmed candidate against a fresh live Site Graph. The revalidation is `kiwe.fresh-sitegraph-revalidation.v1`: it checks current Bricks/Woo capabilities, post types, taxonomy terms, dynamic tags, warnings, and blockers without saving anything.
15. Admins can build rollback readiness for the fresh candidate. The checkpoint is `kiwe.rollback-readiness-checkpoint.v1`: it locks artifact hashes and required rollback captures while explicitly saying no real revision/backup has been captured yet.
16. Admins can resolve the exact apply target. The target lock is `kiwe.target-resolution.v1`: it requires an explicit post/page/template ID and scopes the future adapter to that one WordPress object.
17. Admins can capture the resolved target rollback snapshot. The capture is `kiwe.rollback-capture.v1`: it stores current WordPress fields and relevant Bricks/Kiwe/DSA meta in Kiwe staging for the locked target. It writes Kiwe internal metadata only; it does not save Bricks/page content or create a native WordPress revision.
18. Admins can inspect the resolved target baseline. The inspection is `kiwe.rendered-target-inspection.v1`: it summarizes current post content, Bricks meta shape, estimated Bricks nodes, and operation selector coverage from the protected snapshot. Selector misses are warnings/manual review for first imports, not automatic blockers.
19. Admins can build the minimal adapter shell. The shell is `kiwe.minimal-adapter-shell.v1`: it selects the least-risk future apply route, locks allowed operation IDs, and records the smallest safe mapping step for each operation without saving anything.
20. Admins can record final save approval for the exact shell. The approval is `kiwe.final-save-approval.v1`: it requires an explicit checkbox and locks the shell, rollback capture, rendered inspection, post-apply audit plan, browser smoke plan, and rollback verification plan without saving anything.
21. Admins can build the controlled executor skeleton for the exact save approval. The skeleton is `kiwe.controlled-executor.v1`: it defines the future adapter interface and pre-mutation checklist while keeping `adapterCanSaveNow`, `actualSaveExecuted`, and `mayExecuteMutationNow` false.
22. Admins can prepare the Bricks controlled adapter plan for the exact executor. The artifact is `kiwe.bricks-controlled-adapter.v1`: it maps approved operation IDs to deterministic Bricks/Kiwe adapter instructions and preferred Bricks ability/conversion routes while still keeping `adapterCanSaveNow`, `actualSaveExecuted`, and `mayExecuteMutationNow` false.
23. Admins can build post-apply verification and rollback proof planning for the exact adapter plan. The artifact is `kiwe.post-apply-verification.v1`: it selects one smallest future controlled run, records the post-apply render/audit/smoke checks, and proves the rollback source from the captured snapshot while still keeping `actualApplyExecuted`, `actualPostApplyVerificationRun`, `actualRollbackExecuted`, and `mayExecuteMutationNow` false.
24. Later batches or a live staging-site run can safely apply the staged/proven/authorized/gated/previewed/confirmed/fresh/rollback-ready/target-locked/rollback-captured/render-inspected/shelled/save-approved/executor-planned/adapter-planned/post-proofed candidate through Bricks 2.4 abilities or Bricks import workflows only when the human explicitly runs the smallest controlled mutation and captures real post-apply proof. Until that future run exists, the CLI and WordPress admin apply-plan views/downloads/stages/proofs/authorizations/gates/previews/confirmations/revalidations/rollback-checkpoints/target-locks/rollback-captures/render-inspections/adapter-shells/save-approvals/executor-skeletons/adapter-plans/post-proof artifacts are inspection, approval, interface, and restore-prep contracts only.

## Lead rule

Do not ask a browser AI to crawl the whole plugin or guess a site's categories. Give it:

1. the relevant Kiwe Toolkit context;
2. the Site Graph JSON exported from the target site;
3. the current handoff it must revise.

The model should then output a revised handoff plus a binding report. It should not mutate WordPress directly unless a future trusted apply step explicitly authorizes it.

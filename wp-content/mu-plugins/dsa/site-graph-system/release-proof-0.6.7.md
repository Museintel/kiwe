# Kiwe 0.6.7 Site Graph / Internal AI release proof

Date: 2026-07-22

This file closes the Site Graph + internal AI phase that started with the AI-less graph/data lane and ended with the model-optional advisor enrichment seam.

## Local package proof

The release package must pass these commands before uploading the MU plugin folder to a staging host:

```bash
php -l wp-content/mu-plugins/dsa/includes/AI/Internal_AI_Context_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Internal_AI_Advisor_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Internal_AI_Enrichment_Service.php
php -l wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_AI_Brief_Service.php
php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php
php -l wp-content/mu-plugins/dsa/includes/Rest/Site_Graph_Controller.php
php -l wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php

node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
npm.cmd test --prefix kiwe-ai-toolkit
```

Expected package proof after this batch:

- loader, package entry, and manifest versions match;
- all files in `wp-content/mu-plugins/dsa/` are listed in `package-manifest.json`;
- `package-manifest.json` hashes match the uploaded folder;
- Kiwe AI Toolkit syntax/validator fixtures pass.

## Staging upload proof

After uploading the folder-based MU package, check:

1. WordPress admin loads without the incomplete-package warning.
2. `Kiwe > AI` shows the Kiwe Advisor panel.
3. Advisor focus filters work for `all`, `security`, `headless`, `wp7`, `staging`, and `site_graph`.
4. Advisor enrichment style filters work for `executive`, `developer`, `security`, and `handoff`.
5. Advisor refresh does not execute staging or mutate content.
6. `Kiwe > AI > API access keys` can create and revoke scoped keys.

## API proof

Using a revocable Kiwe AI key with `all` or the matching scoped access:

```text
GET  /wp-json/dsa/v1/ai/status
GET  /wp-json/dsa/v1/ai/site-graph?sampleLimit=8
GET  /wp-json/dsa/v1/ai/site-graph-data/schema
GET  /wp-json/dsa/v1/ai/site-graph-data?resource=site
POST /wp-json/dsa/v1/ai/site-graph-data
GET  /wp-json/dsa/v1/ai/security-brief
GET  /wp-json/dsa/v1/ai/internal-context
GET  /wp-json/dsa/v1/ai/advisor
POST /wp-json/dsa/v1/ai/advisor
GET  /wp-json/dsa/v1/ai/advisor/enrich
POST /wp-json/dsa/v1/ai/advisor/enrich
GET  /wp-json/dsa/v1/ai/site-inspection?sampleLimit=8
```

Expected boundaries:

- Site Graph/Data are read-only.
- SecureTrack brief is redacted.
- Advisor is deterministic/read-only.
- Enrichment returns a bounded model envelope but does not call a model in this adapter.
- Staging executor still requires explicit staging confirmation and operation-specific confirmation flags.

## WordPress 7 / Abilities proof

On a host where the WordPress Abilities API is available, Kiwe should register:

```text
dsa/get-site-graph
dsa/get-site-graph-data-schema
dsa/query-site-graph-data
dsa/get-securetrack-brief
dsa/get-internal-ai-context
dsa/run-internal-ai-advisor
dsa/enrich-internal-ai-advisor
dsa/validate-bindings
dsa/prepare-apply-plan
dsa/stage-apply-plan
```

All abilities above are safe connector surfaces. Only `dsa/stage-apply-plan` writes, and it writes Kiwe review metadata only.

## Dynamic handoff proof

For a real AI-created handoff:

1. Use Site Graph/Data instead of scraping the frontend.
2. Validate `bricks-bindings/kiwe-bindings.json`.
3. Prepare a dry-run apply plan.
4. Stage for trusted review only after validation passes.
5. Use the controlled staging executor only on a confirmed staging host.
6. Run browser smoke after any controlled import/conversion.

Required browser smoke:

- desktop/tablet/mobile/narrow widths;
- no horizontal overflow;
- AppShell dock aligns with selected presentation/orientation/shape;
- header/page launchers open the correct DSA sheet/screen;
- Menu context scrolls to real semantic page sections;
- Search does not auto-open the mobile keyboard in sheet mode;
- Cart FBT rail remains horizontal and readable;
- external popups make the dock yield.

## Phase boundary

This release does not make Kiwe a free-running autonomous writer.

Kiwe can now:

- explain the site through Site Graph;
- fetch normalized headless WordPress/WooCommerce/menu/media data;
- give deterministic internal advisor recommendations;
- prepare a model-enrichment envelope for future WordPress AI Client use;
- validate, prepare, stage, and carefully execute controlled staging operations with explicit flags.

Kiwe still must not silently:

- publish WordPress content;
- save Bricks content;
- mutate WooCommerce;
- run checkout/cart/auth;
- change security enforcement;
- process payments;
- bypass human confirmation.

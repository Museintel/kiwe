# /convert /bricks

This is the browser-AI preparation lane, not the JSON compiler. Its input is the complete accepted HTML/CSS/JS project plus assets and, whenever real site fields are requested, a current SiteGraph connection or export.

Preserve the rendered source, responsive behavior, content and JavaScript. Add stable unique source selectors only where required to describe Bricks binding intent. By default, identify both singular dynamic fields and repeated query regions. `/convert /bricks /dynamictags` limits the pass to singular dynamic text, links, images and supported WooCommerce fields. `/convert /bricks /queryloop` limits the pass to repeated Bricks query-loop regions. The modifiers are mutually exclusive and never change visual design.

Use only post types, taxonomies, term IDs, fields, dynamic tags and query object types proven by SiteGraph or the public Bricks contract. Never invent a site-specific ID or field. Preserve static preview content but mark production regions as dynamic. Record launchers for proven Kiwe capabilities without replacing source popups or interactions that have distinct intent.

Return the complete updated raw project, `bricks-bindings/kiwe-bindings.json` using schema `kiwe.bricks-bindings.v1`, and a binding report covering `queries`, `dynamicFields`, `launchers`, `menuContext`, `assumptions` and `requiresHumanReview`. Do not emit a Bricks template, Bricks JSON, Framework Profile or compiler confidence score. The user submits this package to `seam.kiwe`, whose deterministic compiler alone produces and validates native Bricks JSON.

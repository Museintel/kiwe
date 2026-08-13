# SEAM Compiler authority

Use this context for `/convert /bricks`, `/convert /bricks /seamframework`, `/seamframework`, `/audit /bricksconversion`, and `/audit /seamframework`.

The production authority is the deterministic SEAM Compiler contract in `kiwe-ai-toolkit/contracts/seam-compiler-contract.json`. Browser AI may create and refine source HTML/CSS/JS, explain findings, and request missing proof. It must not manually recreate the compiler, author production Bricks JSON, guess Framework variables/classes, or claim a manual visual PASS.

## Stage 1 — raw Convert

`/convert /bricks` accepts an arbitrary HTML/CSS/JS project or standalone document. It is Framework-neutral: it neither requires nor injects Seam Framework. It discovers pages without route-name assumptions and may split a complete document into dedicated Bricks header, footer, and content templates. It maps authored layout, paint, type, responsive rules, semantic elements, safe interactions, and supported form controls into native Bricks controls. Scoped CSS remains only where Bricks 2.3.10 cannot faithfully represent the source.

The source is the visual contract. A defect already present in the source is reported as source parity, not repaired or counted as a converter defect.

## Mode 2 — optional one-pass Framework

`/convert /bricks /seamframework` is preferred when the source is available: it retains the complete rendered DOM, authored class cascade, variables, attributes, responsive states, and compiler-only evidence until Framework ownership is finished. `/seamframework` remains valid for a successful raw result retained in the same compiler session. Neither path redesigns the source or changes raw Convert behavior. Both emit one project-wide `framework/kiwe-framework-profile.json`, an install-order manifest, and Framework-dependent Bricks templates.

Install order is mandatory:

1. Upload the profile in Kiwe > Framework and push it to Bricks.
2. Confirm the profile installed Theme Style, variables, palette, and project classes.
3. Import the Framework-dependent Bricks templates.

Ownership order:

1. Bricks Theme Style owns body type, heading H1–H6 type, links, and site background.
2. Kiwe/Seam universal variables and palette own universal design primitives.
3. Project variables and reusable project classes own repeated project design.
4. Element-native settings own genuine one-off exceptions.
5. Scoped CSS owns only behavior Bricks cannot express.

Do not duplicate the same visual property in Theme Style, a project class, and an element. Do not add inline CSS variable fallbacks. A missing profile is a failed installation, not permission for templates to carry ghost fallback styling.

## Audits

`/audit /bricksconversion` audits raw native mapping and editability. `/audit /seamframework` executes `validate-seamframework.cjs` against the profile and every dependent template as one package, including install order, consumed-variable definitions, exact profile-installed class IDs, duplicate ownership, local heading locks that should belong to Theme Style, and the compiler-produced `framework/audit-seamframework.json`. A Framework PASS requires 100% package integration; it does not replace raw visual-parity proof.

Visual comparison is valid only when source and candidate captures prove the same CSS viewport, height policy, DPR, and page identity. Browser/hosting overlays must be explicitly masked and reported. A mismatched or contaminated capture is `INCOMPLETE`, never a numeric accuracy score. A PASS requires executable validator proof.

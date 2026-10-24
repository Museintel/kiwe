# Explicit Design Context membership — 6 September 2026

Status: accepted for local RC58; native/browser acceptance pending.

The business workspace manages far more records than a design brief should contain. Treating every public upload as design material obscures client intent and grows exports with unrelated files.

WordPress owns all attachments and comments. Kiwe stores only explicit membership flags on those records, reads fresh public projections, and uses one Design library UI to change selection. The existing selected-user Team model remains independent. Menu composition belongs to one native Navigation workspace; page/category screens provide shortcuts.

A duplicate resource/testimonial option list was rejected because it would drift from source edits and approval. Automatically selecting the existing library was rejected because existence is not client intent. All MIME types remain supported under WordPress's existing upload policy. Public selection grants no new upload or moderation rights.

This is a deliberate narrowing of the general media pool in Design Context. Existing logos/team/service/product image assignments retain authority; legacy resource entries remain retained, without automatic migration. Client Administrator has a dedicated navigation capability without theme or generic menu-item editing. Exported testimonial evidence is additive and is not automatically wired to a new frontend query loop.

[RC58 batch](../DESIGN-LIBRARY-NAVIGATION-2026-09-06.md) owns field contracts, limits, tests, rollout and recovery. Related consumers receive the optional `contextOnly` query argument through the existing SiteGraph reader. The SEAM capability snapshot and Key service contracts are unaffected; future snapshot synchronization must use the declared tool after a canonical source revision is selected.

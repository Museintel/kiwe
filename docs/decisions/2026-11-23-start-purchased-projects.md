# Start owns purchased projects before website installation

23 November 2026. Status accepted for the Version 1.x candidate.

A verified site entitlement from Kiwe Launch creates one durable Start project. The stable entitlement identifier is the idempotency key and the trusted purchaser account owns the record. A purchase can yield multiple site entitlements for the same agency. The checkout process is responsible for payment validation and purchaser authentication.

The hosted Start service holds pre-install state separately from the static versioned authoring guide. Both the web form and MCP call the same project actions. Browser access uses a short-lived, one-time handoff from an authenticated checkout session. MCP uses an audience-bound OAuth access token from the configured issuer. The two channels identify the same purchaser account and share a revision check for writes.

The selected domain is a draft until a managed provider or the owner of an existing WordPress site verifies it. Host provision, native site ownership, Key sender setup and WordPress business content retain their respective authorities. This slice defines the next step from the entitlement and the buyer's hosting choice.

Use the [service integration and test guide](../../services/start-projects/README.md) for the purchase inputs, deployment configuration and executable checks. See the [Version 1 recovery release](https://github.com/Museintel/kiwe-ecosystem/releases/tag/kiwe-version-1) for the frozen rollback checkpoint.

## Existing WordPress site verification

The Kiwe MCP App provides a dedicated read-only site proof operation under `mcp.site.connect`. Its native permission callback checks the designated Kiwe owner on each read. A single-site administrator can authorize where the native owner policy has no designated owner. Existing editorial and authoring permissions retain their established scopes.

Start binds a native OAuth code exchange to the project revision, account, exact site issuer and a one-time callback state. The website's native grant supplies the proof. Start stores only the verified site and its runtime versions. A server request follows the chosen HTTPS origin and pins the validated public DNS addresses. A changed domain retires pending proof and clears the previous link.

## Key and agency ownership

A standalone purchaser has private projects under the Kiwe Launch umbrella. An agency has a separate workspace with its own verified home website. Kiwe Launch itself is the founder’s agency home for the separate Kiwe store and test projects. Each project retains its own verified domain, WordPress ownership, Design Context, sender selection and approval history. Staff or client access to one site applies to that site. The agency home can have its own Design Context.

The agency owner explicitly selects their home website. Start verifies it through an eligible existing site proof from that agency or an exact HTTPS challenge on the agency site. A client project has independent site consent. Account ownership remains the authority for every agency and individual project. Key connection setup retains its established account binding and site-specific sender.

Key stores the WhatsApp pairings and per-site signing credentials. Key and WordPress each require their existing native owner approval for setup. Start sees the exact origin, sender readiness and signed-site proof. The buyer reaches Design Context when WordPress and its Key sender are ready. An optional client-number invite keeps the agency’s working sender available during pairing. Design Context entries are supplied by the authorized humans who know that site’s business.

## Independent agency clients

An agency retains the billing project. Its verified site's client receives a separate Kiwe identity grant after authenticating through Launch and proving the invited email. The same grant admits the client's browser and MCP reads for that site. Access can be revoked without affecting the agency or the client's other projects. WordPress owns native site roles and sessions. A federated WordPress login requires its own implementation and acceptance.

## Design Context authority

WordPress owns the business facts and serves the existing Bricks bindings. Start loads introductory fields through the separate `mcp.start.context` grant and submits one approved change through the native writer. A client or authorized agency user supplies the facts. A browser form can save the edited native field. An MCP proposal opens a human review for the exact site and value. Native state checks protect edits made through WordPress during a Start session. The wider journey remains in WordPress.

## One homepage review

Start prepares one homepage from a scoped native public brief and the owner's design direction. The creative client or configured provider supplies the visual design. WordPress supplies the saved business facts, public records and capability evidence. A proposed preview carries its revision and exact source fingerprint. Only the owner can approve that source in Start. Changes create another revision and preserve earlier approvals.

Keep the complete approved HTML, CSS and JavaScript. Prepare a separate Seam input that excludes the preview runtime and retains its approval receipt. Seam owns Bricks conversion, and WordPress owns native review, placement and publishing. The first homepage approval supplies direction for the later individual pages and templates.

## Pages and Templates

The project checklist combines published records and native route assignments with the active design revision. Fresh WordPress consent authorizes the inventory read. The saved inventory belongs to that site and project revision.

Start invokes the trusted Seam release against the exact current approved homepage. WordPress reviews and imports one private Bricks draft using its existing permission and recovery checks. Start resumes the saved import and reports its native receipt. Source approval in Start and import approval in WordPress are separate decisions. Placement on a public route has its own owner grant and exact native review. For a static homepage, WordPress checks the source draft, target state and template assignment, saves the previous body in encrypted recovery and verifies the updated body. For a posts-only homepage, the same owner review sets up a Home page and relocates the native posts index to an existing assigned or newly created Blog page. Readiness of both assignments precedes the public route switch. A site-bound checkpoint ties interrupted writes to the same pages. Recovery verifies the active receipts, restores the prior Reading settings where applicable and keeps created pages as drafts. Start keeps the acknowledgement.

The project stores drafts by checklist target. Existing Start databases map older drafts to Home automatically. A WordPress owner authorizes public design evidence for the requested revision. The approved homepage sets its design direction, and the owner approves each target’s source separately. A terminal placement receipt belongs to that exact target and import.

Start compiles each approved document in a distinct Seam namespace so different layouts can coexist in the site’s native style registry. WordPress places the reviewed design through native Bricks template conditions or a reviewed page assignment. Original page and post bodies retain their WordPress authority. Authored privacy and terms text must already be present in WordPress. Cart, checkout, payment, order confirmation and account sections use native Bricks Sections rendered only on their assigned WooCommerce route. The placement saves its own encrypted recovery and retires the completed import transport. The exact imported template stays private for reference.

## Managed hosting

An eligible purchaser can request hosted WordPress through the Start form or MCP. Start records the reserved Hostinger temporary address before the setup write. Every follow-up uses that exact address. The selected custom domain remains on the project. Readiness requires both provider completion and a WordPress response for the assigned site. The hosted and existing paths continue toward the site's own authority and Key.

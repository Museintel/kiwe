# Design Context capability vocabulary proof — Kiwe 7.21

Kiwe 7.21 turns approved public Design Context data into a reusable SEAM
capability layer without duplicating owner facts in individual pages.

Live actions:

- `data-kiwe-contact="phone|email|whatsapp|directions"`;
- optional bounded `data-kiwe-contact-message` for WhatsApp;
- `data-kiwe-social="facebook|instagram|x|youtube|pinterest|linkedin"`.

Destinations are enumerated and server-resolved. Unknown actions and missing
records fail closed. Page markup cannot use the Kiwe capability path to
substitute a different phone, email, address or social profile. DSA Links and
Design Context remain the single business-identity record; visitor Profile
continues to own the current visitor's account only.

Bricks receives native URL dynamic tags for phone, email, WhatsApp and
directions. Icon, Button and Text Link elements expose native Kiwe Design
Context controls. Native Bricks Map elements retain map authority and compose
their address from the existing Kiwe store-address tags. No iframe or second
map runtime is introduced.

The deterministic SEAM compiler recognizes existing `tel:`, `mailto:`,
WhatsApp, map/directions and supported social links and adds the smallest
matching capability attribute. Bricks conversion validators fail when those
attributes are lost.

Verification:

```text
node tools/connector/ai-api-contracts.cjs
node kiwe-ai-toolkit/tools/smoke-test.cjs
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-valid --site-graph kiwe-ai-toolkit/fixtures/bricks-conversion-valid/site-graph.json
node tools/release/build-package-manifest.cjs
node tools/release/verify-green-baseline.cjs
```

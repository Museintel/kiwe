# Legacy UI Review Brief

You are reviewing Kiwe's built-in Legacy UI profile.

The purpose is to find visual, spacing, hierarchy, accessibility, density, and responsive issues in the current baseline while preserving the Kiwe Surface contract.

## Scope

Review these areas:

- Profile/account
- Cart and checkout handoff
- Search
- Menu and page table of contents
- Saved items
- Links/social hub
- Notification preferences
- iOS install guide
- AI inbox/report shell
- Games shell
- Dock states: full compact dock, split compact dock, and navigation bar
- Sheet and Classic presentations
- Light and dark mode
- Narrow, compact, and wide layout states

## Constraints

Do not propose:

- new cart, checkout, auth, PhoneKey, Search, Bricks, service-worker, focus, history, or geometry authority
- cloned transactional controls
- remote fonts, remote scripts, trackers, or new runtime JavaScript
- page-specific Bricks IDs or site-specific selectors

Use:

- stable selectors from `screen-payloads.json`
- slots from `slots.md`
- tokens from `token-map.css`
- preview rules from `preview-handoff.md`

## Deliverable

Return a short audit with:

| Priority | Screen/state | Problem | Why it matters | Suggested fix | Contract impact |
| --- | --- | --- | --- | --- | --- |

Use priorities:

- Blocker: breaks use, hides required controls, or violates accessibility/authority.
- High: likely visible to many users or hurts conversion/trust.
- Medium: noticeable visual quality issue.
- Low: polish.

If a fix needs core markup or runtime changes, say so directly. Do not hide runtime changes inside CSS.

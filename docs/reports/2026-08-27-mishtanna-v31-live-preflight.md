# Mishtanna v3.1 live preflight — 2026-08-27

## Scope

Testing site only: `plum-giraffe-539524.hostingersite.com`.
The live Dharwad Pedha site was not changed. No MU-plugin upload was performed
by the agent; the canonical upload folder remains `wp-content/mu-plugins`.

## Live evidence

- Switched from the intermittently failing Chrome connection to the authorized
  in-app browser. WordPress administration works there.
- The updated Database & Cache safety text is present.
- Captured **Pre Mishtanna v3.1 native batch — 2026-08-27** at
  `2026-08-27T04:51:00+00:00`: 154 records, approximately 39 MB,
  integrity display `Ready · 31bdf04b4943`.
- This is a signed, same-site builder/content checkpoint, **not** a full hosting
  backup or a cross-site migration. Users, orders, credentials and media files
  are outside its restore scope.
- Developer reported 330 manifest files, no missing files, and 15 changed files.
  Its check timestamp stayed at `04:37:09` after a reload.
- Database & Cache reported 300 unexpected package files, including nested
  `dsa/assets/...` paths. No unexpected files were removed.
- No clean-conversion run was active. Raw Convert Test Mode and Accessibility
  Preview Mode were both enabled in the old configuration.
- Bricks 2.3.10, PHP 8.3.31, PHP memory limit 512M, execution limit 300 seconds,
  upload/post limits 256M. The separately displayed WordPress memory constant
  was 40 MB; this is not the displayed PHP runtime memory limit.

## Fixes made from this preflight

1. Developer now forces a disk manifest verification on reload and lists the
   exact changed/missing paths. Ordinary frontend requests remain cached.
2. Runtime verification now uses the same CRLF/CR-to-LF normalization as the
   manifest builder for text files. Binary data remains byte-exact. The prior
   raw-byte PHP verifier disagreed with the Node release verifier on Windows.
   A refreshed live check is still required to determine whether any of the
   15 reported mismatches represent genuinely incomplete uploads.
3. The older clean-conversion service no longer snapshots the entire Kiwe
   settings option. It restores only its eight owned diagnostics/Bricks flags,
   preserving rotated credentials and unrelated settings changed during a run.
4. Added executable regression coverage for fresh proof, text/binary hashing,
   field-level rollback, absent options/groups, unrelated option exclusion and
   tampered snapshots. Added these checks to the PHP 8.2/8.3/8.4 CI matrix.

## Pending — do not treat this as visual acceptance

- User upload of the corrected canonical plugin to the test site.
- Fresh live package proof, then live baseline restore/round-trip verification.
- Isolation and removal from active use of the old test templates/pages.
- Import and assign the 11 generated v3.1 templates in proper Bricks/Woo contexts.
- Verify real product media, desktop/mobile layout, cart and checkout behavior.

No baseline restore, template deletion, clean-run activation, new template
import, or WooCommerce page reassignment occurred in this pass.

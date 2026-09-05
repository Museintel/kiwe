# Kiwe main promotion — 5 September 2026

## Outcome

Kiwe `main` was fast-forwarded from `1211c3946518507648cbb5ee96934514625382d5` to `981459327a8207d470a76505e3f63d2d8fc6ba68` after the RC55 product history and senior-developer handoff were verified.

No rebase, merge commit, force push or history rewrite occurred. The historical `codex/phonekey-whatsapp-rc1` branch pointed to the same promotion baseline when the operation completed.

## Evidence before promotion

- `git rev-list --left-right --count origin/main...HEAD` returned `0 9`.
- `git merge-base --is-ancestor origin/main HEAD` passed.
- The candidate worktree was clean and matched `origin/codex/phonekey-whatsapp-rc1`.
- `node tools/release/verify-green-baseline.cjs` passed from a fresh clone after `npm ci` in `kiwe-ai-toolkit/`.
- A dry-run push proved the update was a permitted fast-forward before the real push.
- The resulting `origin/main` and candidate SHA-256 Git object ID matched exactly at `9814593...`.

The fresh toolkit install reported no high or critical npm advisories and one moderate transitive `qs` advisory. It was recorded for a dedicated dependency-maintenance change rather than silently altering the tested RC during branch promotion.

## Product and deployment meaning

The installed Ascendants candidate remains `8.0.0-rc.55`, whose product code commit is `19634ec`. Commits after that code revision contain deployment evidence and handoff documentation; moving them to `main` did not deploy another plugin build or change the live site.

The tracked public Start registry is contract 9.0 while the live registry was observed at 8.8. That remains a separate controlled deployment task. Historical immutable command artifacts retain their original branch URLs; change current public pointers only through the registry generator and release gates.

Seam.kiwe's `SEAMFLOW-UPSTREAM.json` still pins the former Kiwe `main` baseline at `1211c394...`. Its snapshot remains reproducible, but it is not yet synchronized with the promoted Kiwe history. Use Seam's drift and sync commands rather than copying files manually.

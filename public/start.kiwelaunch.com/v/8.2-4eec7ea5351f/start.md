# SEAM Start

Contract version: `8.2`
Updated: `2026-08-26`

Read the matching `command-manifest.json` before acting. Do not crawl the repository and do not use a command remembered from an older conversation.

SEAM has six commands:

- `/ideate` — inspect the supplied project, classify whether SiteGraph is required, report Design Context coverage, then create or refine raw HTML/CSS/JS without imposing a house style.
- `/convert /bricks` — prepare both Bricks dynamic tags and query-loop bindings; add `/dynamictags` or `/queryloop` to request only one binding type.
- `/audit` — inspect an artifact without changing it.
- `/accessibility` — audit accessibility, responsive geometry and light/dark presentation.
- `/fix` — correct proven audit findings while preserving the accepted design.
- `/redo` — replace a failed output from the last accepted artifact and failure evidence.

SiteGraph and its embedded Design Context are attached or safely connected project inputs. They are not commands. `/ideate` first classifies SiteGraph as required, beneficial or not needed. If required evidence is absent, it asks for one safe read-only handoff; when present, it reports the Design Context coverage percentage before changing a file. Seam Framework is an explicit `seam.kiwe` compiler option, not a separate command. Browser AI never emits production Bricks JSON; only the `seam.kiwe` application does.

Unknown commands, aliases and unsupported modifiers must be rejected.

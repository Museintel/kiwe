# SEAM Start

Contract version: `8.4`
Updated: `2026-08-26`

Read the matching `command-manifest.json` before acting. Do not crawl the repository and do not use a command remembered from an older conversation.

Inspect supplied attachments even when the user gives only this start link. If no slash command is present, identify each attachment by content rather than filename, recommend exactly one of the six commands with a one-sentence reason, and wait for that command. Do not silently execute a guessed command. A raw HTML/CSS/JS project needing design work normally suggests `/ideate`; an accepted raw project needing WordPress binding intent suggests `/convert /bricks`; an artifact needing inspection suggests `/audit` or `/accessibility`; a report with proven findings suggests `/fix`; and a failed generated output suggests `/redo`.

SEAM has six commands:

- `/ideate` — inspect the supplied project, classify whether SiteGraph is required, report Design Context coverage, then create or refine raw HTML/CSS/JS without imposing a house style.
- `/convert /bricks` — prepare both Bricks dynamic tags and query-loop bindings; add `/dynamictags` or `/queryloop` to request only one binding type.
- `/audit` — inspect an artifact without changing it.
- `/accessibility` — audit accessibility, responsive geometry and light/dark presentation.
- `/fix` — correct proven audit findings while preserving the accepted design.
- `/redo` — replace a failed output from the last accepted artifact and failure evidence.

SiteGraph and its embedded Design Context are attached or safely connected project inputs. They are not commands. `/ideate` first classifies SiteGraph as required, beneficial or not needed. If required evidence is absent, it asks for one safe read-only handoff. When present, it reports Design Context coverage and a concise, page-relevant Design Context and Design Content usage brief, then waits for the user to say `proceed` before changing or creating files. Seam Framework is an explicit `seam.kiwe` compiler option, not a separate command. Browser AI never emits production Bricks JSON; only the `seam.kiwe` application does.

Unknown commands, aliases and unsupported modifiers must be rejected.

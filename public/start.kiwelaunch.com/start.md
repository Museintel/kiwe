# SEAM Start

Contract version: `8.1`
Updated: `2026-08-25`

Read the matching `command-manifest.json` before acting. Do not crawl the repository and do not use a command remembered from an older conversation.

SEAM has six commands:

- `/ideate` — discover whether this is a new build, redesign or source enhancement, then create or refine raw HTML/CSS/JS without imposing a house style.
- `/convert /bricks` — prepare both Bricks dynamic tags and query-loop bindings; add `/dynamictags` or `/queryloop` to request only one binding type.
- `/audit` — inspect an artifact without changing it.
- `/accessibility` — audit accessibility, responsive geometry and light/dark presentation.
- `/fix` — correct proven audit findings while preserving the accepted design.
- `/redo` — replace a failed output from the last accepted artifact and failure evidence.

SiteGraph and its embedded Design Context are attached or safely connected project inputs. They are not commands. `/ideate` selects an available read-only SiteGraph transport instead of asking the user to understand MCP versus API. Seam Framework is an explicit `seam.kiwe` compiler option, not a separate command. Browser AI never emits production Bricks JSON; only the `seam.kiwe` application does.

Unknown commands, aliases and unsupported modifiers must be rejected.

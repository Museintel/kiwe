# SEAM Start

Contract version: `8.0`
Updated: `2026-08-25`

Read the matching `command-manifest.json` before acting. Do not crawl the repository and do not use a command remembered from an older conversation.

SEAM has six commands:

- `/ideate` — create or refine raw HTML/CSS/JS without imposing a house style.
- `/convert /bricks` — send the accepted raw project to SEAM Compiler for native Bricks output.
- `/audit` — inspect an artifact without changing it.
- `/accessibility` — audit accessibility, responsive geometry and light/dark presentation.
- `/fix` — correct proven audit findings while preserving the accepted design.
- `/redo` — replace a failed output from the last accepted artifact and failure evidence.

SiteGraph and Design Context are attached or connected project inputs. They are not commands. Seam Framework is an explicit compiler option, not a separate command. SEAM Compiler is the sole authority for production Bricks JSON.

Unknown commands and aliases must be rejected.

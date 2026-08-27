# SEAM Start

Contract version: `8.9`
Updated: `2026-08-28`

Read the matching [canonical command manifest](https://start.kiwelaunch.com/command-manifest.json) before acting. If the canonical host is unavailable to the participating AI, read the [GitHub manifest mirror](https://github.com/Museintel/kiwe/blob/codex/phonekey-whatsapp-rc1/kiwe-ai-toolkit/command-manifest.json) and the linked GitHub command context instead. Do not crawl the repository, substitute a similarly named domain, or use a command remembered from an older conversation.

Inspect supplied attachments even when the user gives only this start link. If no slash command is present, identify each attachment by content rather than filename, recommend exactly one of the six commands with a one-sentence reason, and wait for that command. Do not silently execute a guessed command. A raw HTML/CSS/JS project needing design work normally suggests `/ideate`; an accepted raw project needing WordPress binding intent suggests `/convert /bricks`; an artifact needing inspection suggests `/audit` or `/accessibility`; a report with proven findings suggests `/fix`; and a failed generated output suggests `/redo`.

SEAM has six commands:

- [`/ideate`](https://start.kiwelaunch.com/contexts/ideate.md) ([GitHub context mirror](https://github.com/Museintel/kiwe/blob/codex/phonekey-whatsapp-rc1/kiwe-ai-toolkit/contexts/ideate.md)) — inspect the supplied project, establish whether its design is final, enhancement-authorized or redesign-authorized, classify whether SiteGraph is required, report Design Context coverage, then create or refine raw HTML/CSS/JS without imposing a house style.
- [`/convert /bricks`](https://start.kiwelaunch.com/contexts/convert-bricks.md) ([GitHub context mirror](https://github.com/Museintel/kiwe/blob/codex/phonekey-whatsapp-rc1/kiwe-ai-toolkit/contexts/convert-bricks.md)) — return only a guarded binding graph and report; keep the approved source unchanged. Add `/dynamictags` or `/queryloop` to narrow the pass. Only seam.kiwe emits Bricks JSON.
- [`/audit`](https://start.kiwelaunch.com/contexts/audit.md) ([GitHub context mirror](https://github.com/Museintel/kiwe/blob/codex/phonekey-whatsapp-rc1/kiwe-ai-toolkit/contexts/audit.md)) — inspect an artifact without changing it.
- [`/accessibility`](https://start.kiwelaunch.com/contexts/accessibility.md) ([GitHub context mirror](https://github.com/Museintel/kiwe/blob/codex/phonekey-whatsapp-rc1/kiwe-ai-toolkit/contexts/accessibility.md)) — audit accessibility, responsive geometry and light/dark presentation.
- [`/fix`](https://start.kiwelaunch.com/contexts/accessibility.md) ([GitHub context mirror](https://github.com/Museintel/kiwe/blob/codex/phonekey-whatsapp-rc1/kiwe-ai-toolkit/contexts/accessibility.md)) — correct proven audit findings while preserving the accepted design.
- [`/redo`](https://start.kiwelaunch.com/contexts/audit.md) ([GitHub context mirror](https://github.com/Museintel/kiwe/blob/codex/phonekey-whatsapp-rc1/kiwe-ai-toolkit/contexts/audit.md)) — replace a failed output from the last accepted artifact and failure evidence.

SiteGraph and its embedded Design Context are attached or safely connected project inputs. They are not commands. For an existing supplied design, `/ideate` first establishes source authority: final/approved, enhancement-authorized, or redesign-authorized. The command itself never grants redesign permission. If the user's intent is not explicit, the AI asks that one question and stops. It then classifies SiteGraph as required, beneficial or not needed. If required evidence is absent, it asks for one safe read-only handoff. Attaching or connecting that requested evidence never counts as `proceed`: the AI must next report Design Context coverage and a concise, page-relevant Design Context and Design Content usage brief, stop, and wait for the user's explicit `proceed` before changing or creating files. Seam Framework is an explicit `seam.kiwe` compiler option, not a separate command. Browser AI never emits production Bricks JSON; only the `seam.kiwe` application does.

Unknown commands, aliases and unsupported modifiers must be rejected.

# SeamFlow Start

Contract version: `6.78`
Updated: `2026-07-29`
Repository: `Museintel/kiwe`
Product: `SeamFlow`
Purpose: fastest safe entrypoint for external AI, browser AI, IDE AI, MCP clients, skill-capable agents, and Kiwe Companion-assisted Appsite workflows.

Compatibility: this file remains at `KIWE-START.md` so existing prompts keep working. The public flow name is now SeamFlow.

SeamFlow is the external-AI command layer for Kiwe/Seam output. Kiwe Internal AI / Companion is separate: it is the plugin-native, WordPress-aware, Bricks-aware, token-saving assistant that can help SeamFlow when `/usecompanion`, API tools, or MCP routes are available.

If you are an AI reading this file, treat it as the front door. Do not browse, clone, or inspect the whole repository.

## First response rule

Start by reporting this exact contract version:

```text
SeamFlow contract: 6.78
```

Then do one of these:

1. If the human gave `/list`, return the compact command list from the command manifest, then stop.
2. If the human gave another `/command`, route only that command and read only the files listed for that command.
3. If the human gave files but no `/command`, inspect the file contents, classify the current stage, return a compact diagnostic, recommend the next command, and ask which explicit execution command they want:
   - `/execute /stepbystep`, where each command returns its own artifact before the next command starts;
   - `/execute /fullflow`, where you run the complete path and return only final artifacts plus compact pass/fail status.
   The human may add `/audit /eachstep`, `/audit /fix /eachstep`, `/audit /atend`, `/audit /fix /atend`, `/report`, or `/usecompanion`.
4. If the human gave no files and no `/command`, return `/list` plus one short question asking what they want to create, rebuild, audit, fix, convert, or apply.

Classification is read-only and allowed. Audits, fixes, conversion, creation, live API calls, and Companion review require an explicit `/command` or human approval. Keep questions short. Do not start generation until the command or flow is clear.

Command grammar: SeamFlow commands are composable shell-like tokens, not memorized fixed prompts. Parse the whole user command into:

- one primary action token, such as `/execute`, `/rebuild`, `/create`, `/convert`, `/audit`, `/fix`, `/usesitegraph`, `/apply`, `/list`, or `/document`;
- one phase/target token when needed, such as `/stepbystep`, `/fullflow`, `/seamframework`, `/frameworkprofile`, `/bricks`, `/bricksconversion`, `/accessibility`, `/dsatheme`, `/combined`, `/allattached`, `/allflow`, `/previousoutput`, or `/previousaudit`;
- zero or more modifier tokens, such as `/audit`, `/fix`, `/eachstep`, `/atend`, `/report`, `/usecompanion`, `/nonai`, `/replacepreview`, or `/nopreviewdata`.

Example: `/execute /fullflow /audit /fix /eachstep` is not one single hardcoded command. It means primary `/execute`, phase `/fullflow`, and modifiers `/audit`, `/fix`, `/eachstep`. Equivalent valid ordering may be normalized when unambiguous. Unknown, contradictory, or lane-invalid token combinations must stop with `ERROR: KIWE_UNKNOWN_COMMAND` or `ERROR: KIWE_WRONG_LANE`, not guess.

Active-contract rule: after you read this Start file and return the first classification response, treat this loaded Start file as the active contract for the immediate next user `/command` in the same conversation. Do not reload the repository, README, GitHub pages, search results, arXiv, commits, old examples, or the Start file again for that next command. If additional command detail is truly required, fetch only the exact raw machine entry or exact raw command manifest URL from this Start file. If exact raw fetch is unavailable, stop with `ERROR: KIWE_TOOL_UNAVAILABLE` or `ERROR: KIWE_SEARCH_DRIFT`; do not search.

Current-run evidence only: do not use prior Kiwe validation material, old National Chikki/BioVantage attempts, previous browser-AI outputs, local downloads, search results, or "accepted" notes unless the human supplied those exact files in the current turn or explicitly asked you to compare against them. SeamFlow must classify and validate the current artifacts, not inherit conclusions from earlier tests.

No wandering: do not use general web search, arXiv, unrelated GitHub search, commit browsing, stale local examples, or prior-output research to fill gaps. Use this Start file, the machine entry, the command manifest, and only the exact raw context/validator files named by the current command.

Search-drift hard stop: fetching/opening an exact raw URL from this contract is allowed. Searching for that URL, searching GitHub, searching arXiv, searching the web, opening search results, or looking for prior Kiwe/National/BioVantage examples is not allowed. If your environment starts a search instead of direct raw fetch, stop immediately with `ERROR: KIWE_SEARCH_DRIFT`; do not use the search results and do not continue the command.

Validator authority: official lane validators, Kiwe MCP validator tools, or exact copied validator logic are the only PASS authority for importable artifacts. If a browser AI cannot run or exactly apply the relevant validator, it must report `WARN` or `UNVERIFIED`, not `PASS`, and must not say "no blocking findings" for that lane.

Command-central error behavior: if the command, artifact, validator, route, token budget, context window, or requested lane is not valid enough to continue, stop immediately with a compact `STATUS: NEEDS_INPUT`, `FAIL`, or `WARN` response. Include `ERROR:` with a Kiwe error code, the blocker, and the next valid command. Do not invent a manual pass, do not wander through unrelated sources, and do not keep working just to produce something.

Current launch scope: close Seam Framework + Bricks-powered webpages, headers, footers, reusable templates, Framework profiles, Bricks conversion, Site Graph/dynamic intent, and accessibility first. DSA/AppShell theme creation remains part of SeamFlow, but full DSA theme production hardening is the next phase after page-builder flow testing passes.

## Fast machine-readable router

Preferred raw file:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/entry.json
```

Then read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/command-manifest.json
```

Read only the context files named by the matched command. Do not search GitHub for hidden docs.

## Fast navigation tree

Use these direct raw URLs instead of searching:

```text
Start:              https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-START.md
Machine entry:      https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/entry.json
Command manifest:   https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/command-manifest.json
Workflow:           https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/workflow-lite.md
Seam attributes:    https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/seam-attributes-lite.md
Bricks conversion:  https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/bricks-conversion-lite.md
Accessibility:      https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/accessibility-lite.md
Dynamic/Site Graph: https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/dynamic-lite.md
Combined:           https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md
Audit:              https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md
Framework validator:https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/tools/validate-framework-profile.cjs
Bricks validator:   https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs
Access validator:   https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/tools/validate-accessibility.cjs
Output audit:       https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/tools/audit-output.cjs
```

## MCP / tool-capable route

If a Kiwe MCP server or tool is available, prefer tools over reading prose:

1. `kiwe_get_start`
2. `kiwe_get_command_manifest`
3. `kiwe_seamflow_plan` or compatibility alias `kiwe_plan_flow`
4. `kiwe_diagnose_command`
5. `kiwe_route_command`
6. The relevant validator tool for the lane, such as `kiwe_validate_framework_profile`, `kiwe_validate_bricks_conversion`, or `kiwe_validate_accessibility`.

If MCP/tool access fails, continue with the raw files above. Do not block unless the required user artifact is missing.

Ask the human to connect or adopt a Kiwe MCP/skill only when they want live Site Graph/API access, Companion review, or direct validator execution and no Kiwe tool is available. Otherwise use the raw-file route.

If the human chooses `/usecompanion` and no Kiwe MCP/tool is already connected, ask for `KIWE_REST_BASE` and `KIWE_AI_KEY`. First call a bounded Companion status/context route. If it succeeds, say `COMPANION: connected` with the compact route/hash proof before continuing. If it fails or credentials are missing, say `COMPANION: fallback` and continue without Companion.

## Attachment classifier

Use this cheap classifier before deciding a flow. Classify by actual code/content first, not by attachment name:

- Raw HTML/CSS/JS draft: `.html` with `<html`, `<style`, visible page markup, and no Bricks template root.
- Seam page artifact: `website/bricks-paste.html`, official `seam-*` classes, official `data-role`/`data-flow`, Kiwe capability attributes where needed.
- Framework profile: JSON with `schema: "kiwe.framework-profile.v1"`.
- Bricks theme style: JSON with Bricks Theme Style root shape, usually `label` and `settings`.
- Bricks template upload: JSON with top-level `title`, `templateType`, and non-empty `content`, `header`, or `footer`.
- Bricks conversion envelope: JSON with `schema: "kiwe.bricks-conversion.v1"`.
- DSA theme package: `theme.json`, `theme-package.json`, and `css/theme.css` under `appshell-theme/import/[theme-id]/`.
- Combined handoff: contains `combined-preview/`, `website/`, and `appshell-theme/`.

When classification is uncertain, ask whether the human wants an audit first. Do not guess into the wrong lane.

## No-command startup loop

When the human gives only the Start URL, your first response should be:

```text
SeamFlow contract: 6.78
STATUS: NEEDS_INPUT
Attachments detected: yes/no
Artifact diagnostic: type/confidence/stage, if files are present and inspectable
Recommended next command:
Question: choose /execute /stepbystep, /execute /fullflow, or a specific /command. Optional flags: /audit /eachstep, /audit /fix /eachstep, /audit /atend, /audit /fix /atend, /report, /usecompanion.
Commands: use /list for the compact command list
```

If the human supplied attachments, inspect enough file content to determine stage:

- raw creative draft -> recommend `/rebuild /seamframework`;
- Seam page -> recommend `/audit /seamframework`;
- Framework profile -> recommend `/audit /frameworkprofile`;
- Bricks template or conversion -> recommend `/audit /bricksconversion`;
- DSA theme -> recommend `/audit /dsatheme`;
- combined handoff -> recommend `/audit /combined`;
- any visual artifact after the above passes -> recommend `/audit /accessibility`.

If the human supplied `/commands`, do those commands only. Do not add creative phases, audits, fixes, docs, or full-flow execution unless those commands require them or the human approves them.

## Standard flow map

Execution commands:

```text
/execute /stepbystep   -> run the next safe phase only, return its artifact, then stop
/execute /fullflow     -> run the complete safe path to the final artifact set
/audit /eachstep       -> run audit/fix gates after every phase before continuing
/audit /fix /eachstep  -> after each phase, audit; if failed, fix the actual artifact and re-audit until PASS or NEEDS_INPUT before moving on
/audit /atend          -> run generation/conversion phases first, then final audits before delivery
/audit /fix /atend     -> run generation/conversion phases first, then audit/fix/re-audit all required closure lanes until PASS or NEEDS_INPUT before delivery
/report                -> in /execute /stepbystep, return the current phase file plus compact report and wait for the human to say continue
/usecompanion          -> optional bounded Kiwe Companion assist; falls back without blocking
```

Default audit cadence: when unsure, prefer `/audit /eachstep` for production/importable files and `/audit /atend` only for quick exploratory drafts.

## Audit closure law

SeamFlow does not close because an AI says the output "looks good." SeamFlow closes only when the required `/audit` command for the current artifact lane returns `PASS` after any needed `/fix` loops.

The loop is:

```text
1. Run the matching /audit command for the current lane.
2. If audit fails, run the matching /fix command on the actual current artifact.
3. Re-run the same /audit command.
4. Repeat until PASS, or stop as NEEDS_INPUT if the same blocker repeats or required source/authority is missing.
```

Required closure audits by detected start point:

```text
raw HTML/CSS/JS draft      -> /audit /seamframework, /audit /frameworkprofile, /audit /bricksconversion, /audit /accessibility
Seam page artifact         -> /audit /seamframework, /audit /frameworkprofile, /audit /bricksconversion, /audit /accessibility
Framework profile          -> /audit /frameworkprofile
Bricks template/conversion -> /audit /bricksconversion, /audit /accessibility
DSA theme package          -> /audit /dsatheme, /audit /accessibility
combined handoff           -> /audit /combined, /audit /accessibility
```

`/audit /eachstep` means each phase must pass its own audit before the next phase starts. `/audit /fix /eachstep` makes the repair loop explicit: phase -> audit -> fix actual artifact if needed -> same audit again -> repeat until PASS or NEEDS_INPUT -> then move to the next phase. `/audit /atend` means generation/conversion can proceed first, but final delivery still requires every relevant closing audit to pass. `/audit /fix /atend` makes the final repair loop explicit across all required closing lanes. In all audit modes, a failed audit cannot be ignored.

`/report` is an interaction flag. In `/execute /stepbystep`, it means stop after the current phase closes, return the phase artifact plus a compact report of what was generated, audited, fixed, and still warned, then wait for the human to say `continue` before the next phase. In `/execute /fullflow`, `/report` does not pause between phases unless the human explicitly asked for step-by-step; it only adds a compact final phase ledger.

## Second-pass audit and fix commands

When a browser AI has already produced one or more output files, the human should not need to list every lane by hand.

```text
/audit /allattached   -> classify all attached/current files and run every matching lane audit
/fix /allattached     -> fix every failed attached/current lane, then rerun matching audits
/audit /allflow       -> run every closure audit required by the detected SeamFlow start point/current stage
/fix /allflow         -> repair failed lanes across that detected flow, then rerun every closure audit
/audit /allattached /allflow -> classify every attached/current file and run every closure audit required by the detected flow
/audit /previousoutput -> audit the files generated in the immediate previous AI output in this same session
/fix /previousoutput  -> fix the files generated in the immediate previous AI output, using matching audit/fix loops
/fix /previousaudit   -> fix only the failures from the immediately previous audit result, then rerun that same audit scope
```

These are not creative commands. They must not rebuild from scratch, redesign the page, add DSA/combined output, create docs, or use stale files. They are the browser-AI second-try loop: inspect current files, audit all relevant lanes, fix actual failures, and stop only at PASS or NEEDS_INPUT.

`/previousoutput` is a source selector, not a memory search. It means only the files generated by the AI in its immediate previous output in the same conversation/session. If those files are not directly accessible, stop with `ERROR: KIWE_PREVIOUS_OUTPUT_MISSING` and ask the human to attach the output files or rerun the previous command. Do not search downloads, old sandboxes, old chat messages, or previous project attempts.

`/fix /previousaudit` requires the previous audit findings to be present in the current conversation or supplied as a file. If the previous audit is missing, ambiguous, stale, or not tied to the current artifacts, stop with `ERROR: KIWE_PREVIOUS_AUDIT_MISSING`.

If the human writes non-canonical wording such as `/fix /previouspass`, do not execute it and do not treat it as a hidden alias. Return `ERROR: KIWE_PREVIOUS_AUDIT_MISSING`, explain that the intended canonical command is `/fix /previousaudit`, and suggest `/audit /allattached /allflow` first when no previous audit findings exist.

## Command-central error handling

Use these compact errors instead of improvising:

```text
KIWE_UNKNOWN_COMMAND          -> unknown or misspelled command token; stop and suggest /list or the nearest valid command
KIWE_MISSING_ARTIFACT         -> required current files/attachments are missing; stop and ask for them
KIWE_WRONG_LANE               -> supplied artifact does not qualify for the requested command; stop and suggest the matching command
KIWE_STALE_SOURCE_BLOCKED     -> only stale/prior outputs are available; stop and request current files
KIWE_VALIDATOR_UNAVAILABLE    -> official validator/exact validator logic cannot run; report WARN/UNVERIFIED, not PASS
KIWE_MANUAL_PASS_BLOCKED      -> command needs deterministic audit but only manual confidence is available; stop or report WARN
KIWE_PREVIOUS_AUDIT_MISSING   -> /fix /previousaudit was requested without the immediately previous audit findings
KIWE_PREVIOUS_OUTPUT_MISSING  -> /previousoutput was requested but the immediate previous output files are not accessible
KIWE_CONTEXT_WINDOW_RISK      -> requested full flow is too large for the current AI/session; suggest /execute /stepbystep /audit /eachstep
KIWE_TOKEN_BUDGET_RISK        -> command is likely to waste tokens; suggest a smaller command or /audit /allattached first
KIWE_TOOL_UNAVAILABLE         -> MCP/API/browser/validator tool unavailable; use raw route if possible, otherwise stop
KIWE_SEARCH_DRIFT             -> a search engine, arXiv, GitHub search, commit browsing, or prior-example lookup was used or attempted instead of exact raw URL/context routing
KIWE_SITEGRAPH_REQUIRED       -> command explicitly requires live Site Graph/API data that was not supplied
KIWE_COMPANION_FALLBACK       -> /usecompanion requested but unavailable; continue only if the base command can run without it
```

Error response shape:

```text
STATUS: NEEDS_INPUT | FAIL | WARN
SeamFlow contract: 6.78
ERROR: KIWE_...
Command:
Current artifact:
Why stopped:
Next valid command:
```

For a raw HTML/CSS/JS draft, the recommended webpage/header/footer/template-to-Bricks path is:

```text
/rebuild /seamframework
/audit /seamframework
/fix /seamframework       # only if audit fails
/create /frameworkprofile
/audit /frameworkprofile
/fix /frameworkprofile    # only if audit fails
/convert /bricks
/audit /bricksconversion
/fix /bricksconversion    # only if audit fails
/audit /accessibility
/fix /accessibility       # only if audit fails
```

For header, footer, and reusable template work, follow the same path but set the Bricks target type correctly during `/convert /bricks`:

```text
homepage/body -> templateType: "content", content[]
header        -> templateType: "header", header[]
footer        -> templateType: "footer", footer[]
section       -> templateType: "section", content[]
```

For an existing Bricks template or Bricks conversion artifact:

```text
/audit /bricksconversion
/fix /bricksconversion    # only if audit fails
/audit /accessibility
/fix /accessibility       # only if audit fails
```

For a Framework profile:

```text
/audit /frameworkprofile
/fix /frameworkprofile    # only if audit fails
```

For a DSA/AppShell theme package:

```text
/audit /dsatheme
/fix /dsatheme            # only if audit fails
/audit /accessibility
/fix /accessibility       # only if audit fails
```

For combined Appsite work, use the website/page path plus DSA theme audit and combined audit. Do not run `/convert /bricks` on AppShell/DSA theme files.

## Output discipline

Documentation is opt-in.

Do not create README files, notes, reports, ZIP files, duplicate previews, or long explanations unless the human includes `/document` or explicitly asks for documentation.

Default final response shape:

```text
STATUS: PASS | FAIL | WARN | NEEDS_INPUT
SeamFlow contract: 6.78
Command:
Artifact classification:
Files returned:
Blocking findings:
Warnings:
Next suggested command:
```

## 100% Seam rule

For `/rebuild /seamframework`, `/convert /bricks`, `/audit /bricksconversion`, and `/fix /bricksconversion`, do not settle for "renders okay."

The output must preserve design quality and express it through Kiwe/Seam Framework integration:

- official Seam semantic roles/classes/flows;
- Kiwe/Appsite capability attributes instead of duplicate runtime behavior;
- Kiwe/Seam variables or declared project variables for visual values;
- Bricks-native settings/global classes/global variables before custom CSS when producing Bricks;
- real fluid clamps only when source responsive states prove different min/max values;
- no no-op clamps such as `clamp(22px, 22px, 22px)`;
- no direct component colors such as `#fff`, `rgba(...)`, hardcoded gradients, or `--pack-bg: #...` inside Bricks element settings/global classes/custom CSS.

Literal colors and fixed values are allowed at the token/global-variable definition layer when they are named design inputs. Page and Bricks output must consume those names instead of copying anonymous values.

## Accessibility flow

`/audit /accessibility` and `/fix /accessibility` are final-stage and mid-stage tools. They must check:

- WCAG contrast for text, pills, cards, focus, controls, and foreground/background pairs;
- light and dark proof using Kiwe `data-kiwe-theme` and Bricks `data-brx-theme`;
- reduced motion when motion exists;
- critical clipping/overflow where visible text becomes unreadable or unreachable;
- preservation of Seam classes, Kiwe capability attributes, Bricks dynamic tags, query-loop intent, DSA selectors, and AppShell boundaries.

Accessibility fixes should use existing tokens and classes first. Add new project variables only when a real design value is missing and name them clearly.

## Full-flow mode

If the human chooses full-flow execution, run phases in order and stop at the first blocking audit failure that cannot be fixed from the supplied artifact. Return only the current canonical artifact for the last completed/fixed phase and the compact status.

Do not end a full-flow, step-by-step flow, or mid-stream resumed flow until the closure audits for the detected start point have passed. If a browser AI cannot run the official validator, it must still follow the lane audit context exactly and report that official execution was unavailable; it must not call the flow complete from visual confidence alone.

If full-flow succeeds from raw HTML/CSS/JS to Bricks, the final default artifacts are:

- `framework/kiwe-framework-profile.json`
- `bricks-template/[page-name]-template-upload.json`

Do not include AppShell/DSA theme files unless the human requested DSA theme or combined mode.

Full-flow is sequential internally. Even when the human chooses `/execute /fullflow`, read and act phase-by-phase; do not load every context at once, do not jump directly to a final package, and do not move to the next phase until the current phase audit/fix loop closes.

Site Graph is an enhancement lane, not a false hard gate for basic Bricks conversion. If no Site Graph/API/export is supplied, complete the static Seam + Framework + Bricks template conversion with dynamic intent/manual-review markers where needed, then suggest `/usesitegraph` as the next command. Stop for Site Graph only when the human explicitly requested `/usesitegraph`, real target-site IDs, live dynamic binding verification, or staging/apply authority.

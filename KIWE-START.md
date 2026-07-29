# Kiwe Start

Contract version: `6.64`
Updated: `2026-07-29`
Repository: `Museintel/kiwe`
Purpose: fastest safe entrypoint for browser AI, IDE AI, MCP clients, and Kiwe Companion-assisted Appsite workflows.

If you are an AI reading this file, treat it as the front door. Do not browse, clone, or inspect the whole repository.

## First response rule

Start by reporting this exact contract version:

```text
Kiwe Start contract: 6.64
```

Then do one of these:

1. If the human gave `/list`, return the compact command list from the command manifest, then stop.
2. If the human gave another `/command`, route only that command and read only the files listed for that command.
3. If the human gave files but no `/command`, classify the files and ask which flow they want:
   - step-by-step flow, where each command returns its own artifact before the next command starts;
   - full-flow execution, where you run the complete path and return only final artifacts plus compact pass/fail status.
4. If the human gave no files and no `/command`, ask what they want to create, rebuild, audit, fix, convert, or apply.

Keep the question short. Do not start generation until the command or flow is clear.

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

## MCP / tool-capable route

If a Kiwe MCP server or tool is available, prefer tools over reading prose:

1. `kiwe_get_start`
2. `kiwe_get_command_manifest`
3. `kiwe_plan_flow`
4. `kiwe_diagnose_command`
5. `kiwe_route_command`
6. The relevant validator tool for the lane, such as `kiwe_validate_framework_profile`, `kiwe_validate_bricks_conversion`, or `kiwe_validate_accessibility`.

If MCP/tool access fails, continue with the raw files above. Do not block unless the required user artifact is missing.

Ask the human to connect or adopt a Kiwe MCP/skill only when they want live Site Graph/API access, Companion review, or direct validator execution and no Kiwe tool is available. Otherwise use the raw-file route.

## Attachment classifier

Use this cheap classifier before deciding a flow:

- Raw HTML/CSS/JS draft: `.html` with `<html`, `<style`, visible page markup, and no Bricks template root.
- Seam page artifact: `website/bricks-paste.html`, official `seam-*` classes, official `data-role`/`data-flow`, Kiwe capability attributes where needed.
- Framework profile: JSON with `schema: "kiwe.framework-profile.v1"`.
- Bricks theme style: JSON with Bricks Theme Style root shape, usually `label` and `settings`.
- Bricks template upload: JSON with top-level `title`, `templateType`, and non-empty `content`, `header`, or `footer`.
- Bricks conversion envelope: JSON with `schema: "kiwe.bricks-conversion.v1"`.
- DSA theme package: `theme.json`, `theme-package.json`, and `css/theme.css` under `appshell-theme/import/[theme-id]/`.
- Combined handoff: contains `combined-preview/`, `website/`, and `appshell-theme/`.

When classification is uncertain, ask whether the human wants an audit first. Do not guess into the wrong lane.

## Standard flow map

For a raw HTML/CSS/JS draft, the recommended website-to-Bricks path is:

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
Kiwe Start contract: 6.64
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

If full-flow succeeds from raw HTML/CSS/JS to Bricks, the final default artifacts are:

- `framework/kiwe-framework-profile.json`
- `bricks-template/[page-name]-template-upload.json`

Do not include AppShell/DSA theme files unless the human requested DSA theme or combined mode.

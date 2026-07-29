# Kiwe Start

Contract version: `6.63`
Updated: `2026-07-29`
Repository: `Museintel/kiwe`
Purpose: fastest safe entrypoint for browser AI, IDE AI, MCP clients, and Kiwe Companion-assisted workflows.

If you are an AI reading this file, treat it as the front door. Do not browse, clone, or inspect the whole repository.

## First response rule

Start by reporting this exact contract version:

```text
Kiwe Start contract: 6.63
```

Then do one of these:

1. If the human gave `/list`, return the compact command list from the command manifest, then stop.
2. If the human gave another `/command`, route only that command and read only the files listed for that command.
3. If the human gave files but no `/command`, classify the files and ask which flow they want:
   - step-by-step flow, where each command returns its own file before the next command starts;
   - full-flow execution, where you run the complete path and return only final artifacts plus compact pass/fail status.
4. If the human gave no files and no `/command`, ask what they want to create or repair.

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
3. `kiwe_diagnose_command`
4. `kiwe_route_command`
5. The relevant validator tool for the lane, such as `kiwe_validate_bricks_conversion`, `kiwe_validate_accessibility`, or `kiwe_validate_framework_profile`.

If MCP/tool access fails, continue with the raw files above. Do not block unless the required user artifact is missing.

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

For a raw HTML/CSS/JS draft, the recommended path is:

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

## Output discipline

Documentation is opt-in.

Do not create README files, notes, reports, ZIP files, duplicate previews, or long explanations unless the human includes `/document` or explicitly asks for documentation.

Default final response shape:

```text
STATUS: PASS | FAIL | WARN | NEEDS_INPUT
Kiwe Start contract: 6.63
Command:
Files returned:
Blocking findings:
Warnings:
Next suggested command:
```

## 100% Seam rule

For `/rebuild /seamframework`, `/convert /bricks`, `/audit /bricksconversion`, and `/fix /bricksconversion`, do not settle for “renders okay.”

The output must preserve design quality and express it through Kiwe/Seam Framework integration:

- official Seam semantic roles/classes/flows;
- Kiwe/Appsite capability attributes instead of duplicate runtime behavior;
- Kiwe/Seam variables or declared project variables for visual values;
- real fluid clamps only when source responsive states prove different min/max values;
- no no-op clamps such as `clamp(22px, 22px, 22px)`;
- no direct component colors such as `#fff`, `rgba(...)`, hardcoded gradients, or `--pack-bg: #...` inside Bricks element settings/global classes/custom CSS.

Literal colors and fixed values are allowed at the token/global-variable definition layer when they are named design inputs. Page and Bricks output must consume those names instead of copying anonymous values.

## Full-flow mode

If the human chooses full-flow execution, run the phases in order and stop at the first blocking audit failure that cannot be fixed from the supplied artifact. Return only the current canonical artifact for the last completed/fixed phase and the compact status.

If full-flow succeeds from raw HTML/CSS/JS to Bricks, the final default artifacts are:

- `framework/kiwe-framework-profile.json`
- `bricks-template/[page-name]-template-upload.json`

Do not include AppShell/DSA theme files unless the human requested DSA theme or combined mode.

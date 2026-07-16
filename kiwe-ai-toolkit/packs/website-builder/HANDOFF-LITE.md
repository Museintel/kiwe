# Kiwe Framework handoff lite

The full `framework-system/` folder is the source/reference kit. For most web developers, designers, or AI assistants, start with this smaller reading set instead of trying to mentally load every file at once.

## Minimum files to share first

Share these files for a normal website page, Bricks section, or standalone preview assignment:

1. `README.md`
2. `prompt.md`
3. `HANDOFF-MODES.md` if the assignment might include both website/page work and a DSA AppShell theme
4. `contracts/seam-vocabulary.md`
5. `contracts/seam-vocabulary.json`
6. `contracts/seam-class-vocabulary.md`
7. `contracts/seam-class-vocabulary.json`
8. `contracts/token-map.css`
9. `runtime/seam.css`
10. `runtime/seam.js`
11. `bricks/bricks-capabilities.json`
12. `bricks/BRICKS-INTEGRATION.md`

Only add `source-map.md`, `runtime/seam-dev.js`, `tools/`, and `references/` when the person is auditing internals or proposing framework changes.

## Correct design target

Do not ask for "zero custom CSS". That makes most real marketing/editorial pages look generic or broken.

Ask for:

- a standalone previewable HTML page;
- a `bricks-paste.html` file that is ready to paste/import through Bricks HTML-to-Bricks;
- CSS that consumes Kiwe/Seam variables from `token-map.css` and `runtime/seam.css`;
- public Seam roles/flows/tones/states where they describe the structure;
- reusable generic component/layout classes from the Seam Class Vocabulary for the actual art direction;
- no duplicate cart, search, save, checkout, auth, AI, or Bricks-query behavior.

This is not a Kiwe AppShell theme handoff. Kiwe AppShell themes use `ui-system/` and style the DSA sheets/screens/dock around existing capabilities.

Seam roles are semantic/headless by default. Do not ask an AI to maximize `data-role` usage as a visual design method. Use Seam for meaning, flow, tokens, states, and builder portability; use site CSS and reusable generic classes from `seam-class-vocabulary.json` for the actual website look. Seam core intentionally does not ship starter card/button/modal visuals, padding, radius, border, shadow, or background.

## Bricks path

Bricks 2.4 beta includes an `includes/html-to-bricks` converter pipeline. This means a good standalone preview should be built so it can travel into Bricks:

- semantic HTML;
- class-based CSS;
- variables in `:root` or reusable classes;
- minimal inline styles;
- no localStorage behavior for DSA-owned actions;
- no hardcoded generated Bricks element IDs.

The preview is allowed to use mock content and placeholder interactions, but production handoff must say which interactions are placeholders and which are Kiwe/DSA-owned.

Do not accept a React/Vite/Tailwind/shadcn application as the handoff unless the assignment explicitly asked for a separate app prototype. Kiwe website handoffs should be plain HTML/CSS with optional preview-only JS, because the target path is Bricks HTML-to-Bricks.

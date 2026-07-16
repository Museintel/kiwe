# Theme intake audit - 2026-07-15

This audit reviewed the first external-AI marketplace handoffs produced from the `ui-system/` brain after the Legacy and Kiwe 2027 references were added.

## Inputs

- `ui/aurora`
- `ui/aurora 2`
- Text-only handoff pasted as "Glass Bento"

The user prompt given to each AI was:

> create a new ui for the my plugin by following the prompt.md and i want a totally new and ultra modern design (not neo brutalist)

## System-level finding

The generated handoffs showed convergence around generic ultra-modern cues:

- two of the outputs used an Aurora name
- three outputs used glass/frosted/blurred surfaces
- two outputs leaned toward similar floating-card AppShell layouts

This means the prompt was strong on safety and package shape, but not strong enough on originality. The prompt has therefore been tightened to require a distinct visual thesis, avoid default Aurora/Glass/Flow/Bento naming unless requested, and document how the theme differs from Legacy and Kiwe 2027.

## Package results

| Handoff | Validation | Production fit | Decision |
| --- | --- | --- | --- |
| Aurora Glass | Valid only when extracted from the zip and pointed at `theme-handoff/import/aurora-glass`; the flattened `ui/aurora` folder is not valid as an import package. | Too generic, uses `.dsa-visual-marketplace` as the main runtime hook, which the live plugin does not currently emit as a concrete theme profile. | Do not promote yet. Keep as a marketplace sample only after runtime marketplace profile loading exists. |
| Aurora Flow | Valid at `ui/aurora 2/theme-handoff/import/aurora-flow`. | Best candidate technically. It scopes to `aurora-flow`, covers FBT rail and dock shapes, and uses a listed local asset. Still visually close to Aurora Glass/frosted-card territory. | Candidate, but not promoted until visual distinction and marketplace runtime loader are ready. |
| Glass Bento | Text handoff only. | Not production-safe as written. It introduces `.glass-bento-grid` and `.glass-bento-cell` wrappers while claiming no core/plugin change is needed. Kiwe does not emit those wrappers today. Preview also used a remote Unsplash image. | Reject as installable theme. Use as evidence that the prompt needed selector-fit and preview-asset rules. |

## Follow-up requirements added to prompt.md

- Distinctness note against Legacy and Kiwe 2027.
- Avoid common generic names and concepts for broad "ultra modern" requests.
- Selector-fit checklist so new wrappers or adapter requirements are not hidden inside CSS.
- Preview-only assets must be local or pure CSS/HTML; no remote images/fonts/scripts.
- Optional absent-data state, especially Links site score hidden when absent.

## Integration note

Current production runtime normalizes visual profile selection to built-in profiles. A marketplace package can validate today, but a full production install needs either:

1. promotion into a built-in profile with explicit admin/runtime mappings, or
2. a marketplace theme loader that registers validated CSS packages and emits the selected marketplace profile class/data value.

Do not add a package to the live selector just because it passes `validate-package.cjs`. Passing validation proves the first safety boundary only; it does not prove the design is distinct, selector-fit, visually approved, or runtime-loadable.

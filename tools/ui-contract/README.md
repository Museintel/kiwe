# Kiwe Surface UI Contract Harness

This harness is outside `wp-content/mu-plugins`, so fixtures and screenshots never enter the production package.

It renders canonical Menu, Profile, Cart, Links, and Search fixtures across 320px phone, phone, resized-desktop, tablet, and desktop viewports in light and dark modes. Tablet and desktop also run the vertical-dock contract, producing 70 variants. Each run creates screenshots and validates:

- measured layout state;
- viewport overflow;
- dock/context alignment;
- context placement above the dock;
- accessible control names;
- unique IDs and one active dialog;
- minimum geometry-engine dock targets.

Run with Node and Playwright available:

```powershell
node .\tools\ui-contract\run.cjs
```

In Codex Desktop, the runner automatically locates the bundled Playwright package. Elsewhere, install Playwright locally or set `DSA_PLAYWRIGHT_PATH` to its package directory.

Generated artifacts are written to `tools/ui-contract/artifacts/`. They are evidence, not runtime files. A future marketplace package must pass this harness plus live assistive-technology and device QA before acceptance.

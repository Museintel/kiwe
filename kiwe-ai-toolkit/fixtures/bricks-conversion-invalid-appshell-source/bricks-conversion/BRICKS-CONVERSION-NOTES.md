# Invalid Bricks conversion fixture

This fixture intentionally points `source.html` at `combined-preview/index.html`.

Expected result: `validate-bricks-conversion` must fail because `/convert /bricks` may convert only `website/bricks-paste.html`. Combined previews, AppShell previews, theme packages, DSA sheet/screen/dock/navbar markup, `theme-package.json`, and `css/theme.css` are Kiwe AppShell/theme lanes, not Bricks page sources.

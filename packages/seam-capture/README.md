# SEAM Capture

`SEAM Capture` runs untrusted HTML/CSS/JS in a fresh Chromium context on the compiler plane. It never runs inside WordPress or a public request.

It records stable DOM identities, accessibility roles/names, multi-viewport boxes, scroll geometry, selected computed render properties, custom properties, matching stylesheet rules, pseudo-elements, assets, stylesheet/network hashes, screenshots, and execution diagnostics.

The launcher uses `SEAM_CHROME_PATH` when set, then discovers system Chrome/Edge. `playwright-core` is used deliberately so CI/local workers can use their managed browser installation without downloading a second Chromium build.

```text
npm install --prefix packages/seam-capture
node packages/seam-capture/tools/capture-page.cjs input.html output-directory
node packages/seam-capture/tools/merge-captures.cjs merged-output capture-1440/seam-capture.json capture-390/seam-capture.json
npm test --prefix packages/seam-capture
node packages/seam-capture/tools/summarize-evidence.cjs capture-directory compile-directory evidence.json
```

Use `--proof-mode` for screenshot/geometry/style/accessibility comparison runs. It retains the rendered pixels, computed properties, boxes, semantics, diagnostics, and resource integrity required by SEAM Visual Proof while omitting compiler-only cascade provenance, custom-property inventories, and pseudo-element details. Full compiler captures remain the default.

Large matrices may be captured one viewport per worker and combined with `merge-captures.cjs`. The merge validates matching source and engine identities, rejects duplicate viewport evidence, verifies every screenshot hash, and refuses resources whose bytes changed between workers.

Remote asset requests are blocked for local bundles unless `--allow-remote-assets` is explicitly supplied. Local bundle paths are served from a loopback-only ephemeral server with traversal protection.

The canonical matrix is 1440, 1280, 991, 768, 478, 375, and 320 CSS pixels. Use `--viewports 1280,478` for a smaller diagnostic run. Capture IDs, bundle-relative resource URLs, source hashes, and deterministic clock/randomness make evidence reproducible; screenshots remain the browser-render truth used by later visual-difference gates.

The evidence summarizer refuses invalid compiler output, verifies every screenshot against its declared byte length and SHA-256, runs the typed-contract, Framework-profile, and native-Bricks validators, and writes a path-independent compact proof manifest. Raw captures and screenshots can therefore remain content-addressed build artifacts instead of large Git blobs.

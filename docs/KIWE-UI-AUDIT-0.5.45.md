# Kiwe UI Audit 0.5.45

## Fixed in 0.5.45

- Sheet backdrop click contract: sheets now close when the user clicks the real backdrop outside the sheet panel.
- Sheet handle alignment: the drag/close affordance is centered against the full sheet panel, not only the inner padded content.
- Navbar inactive geometry: horizontal navbar mode now stays full viewport width before and after a sheet opens.
- Mobile navbar context contract: horizontal navbar dock context now uses the same full viewport axis as the dock and sits flush to it.
- Vertical full-height interaction bug, partially mitigated: inactive dock context no longer expands into a page-wide fixed layer in vertical right/full-height sheets mode. The later `pan-y` gesture mitigation improved inactive vertical navbar scrolling, but live right/left full-height Navigation bar scroll/click interference remains parked for a named geometry-strategy pass.
- Context pointer contract: the dock context shell is pass-through, while actual context controls remain interactive.

## Newly Identified Issues

- Context measurement is still mixed between visual presentation and behavior. `applySurfaceGeometry()` handles the current navbar/sheet cases, but future presentation variants should receive explicit geometry strategies instead of growing conditionals.
- Dock context currently has special handling for horizontal navbar and inactive vertical navbar. Other dock context presentations should be tested against left/top edge combinations before they are considered contract-complete.
- Parked: vertical right/left full-height Navigation bar can still interfere with page scroll and page clicks on some Sheet/Desktop configurations. Do not add more tactical pointer layering until the Navigation bar geometry strategy is split and live-browser proven.
- The admin UI exposes many placement controls at once. The settings model is powerful, but the screen needs grouped presets and conditional disclosure so users do not create contradictory combinations without understanding the result.
- Screens and sheets share content, but the content typography and truncation rules still need a stricter shared card/content token pass. This is visible in product/cart cards where text can truncate or scale differently between surfaces.
- Tablet mode is still not a first-class breakpoint contract. The current geometry moves from mobile to desktop behavior without a named tablet intent.

## Next Audit Pass

- Test every dock presentation across bottom/top/left/right with no overlay, sheet overlay, and classic screen overlay.
- Add an admin settings preview matrix for mobile, tablet, and desktop contract states.
- Normalize card typography, line clamps, and overflow behavior under shared content tokens.
- Split geometry rules into named strategies for dock, navbar, sheet, and screen presentations.

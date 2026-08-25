# /accessibility and /fix

Audit accessibility and visual robustness across keyboard use, focus, landmarks, names, headings, labels, reduced motion, contrast, readable text, overflow, clipping, collision, touch targets, responsive spacing, alignment and light/dark presentation. Distinguish source defects from conversion defects.

Dark mode must preserve brand identity and visual hierarchy, not turn every surface black. Keep owner-locked brand colors, derive accessible supporting surfaces deliberately, and test foreground/background pairs in their actual states. Decorative text and imagery must remain decorative; do not promote low-opacity or layered art into primary readable content.

`/accessibility` produces evidence and a report without changing the artifact. `/fix` applies only proven findings, preserves accepted art direction and behavior, then re-runs the same checks and returns before/after scores. Prefer native Bricks settings for Bricks artifacts and native CSS/HTML semantics for raw projects. Flag what cannot be fixed safely instead of guessing.

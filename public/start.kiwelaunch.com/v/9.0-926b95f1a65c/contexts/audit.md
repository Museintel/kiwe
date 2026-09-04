# /audit and /redo

Audit is read-only. Inspect the supplied artifact against its accepted source, declared contract and available rendered evidence. Report concrete findings with severity, selector or element ID, breakpoint, expected result, observed result and evidence. Scores must be reproducible from findings, not intuition.

For `/redo`, treat the last accepted artifact as authority and the failed output only as evidence. Re-run the responsible stage, close each proven failure and report unresolved items. Do not stack speculative patches on top of the failed artifact and do not redesign unrelated areas.

For a binding-only handoff, read `contexts/convert-bricks.md` and the bindings schema. Check the graph against the unchanged originals: exact file/selector cardinality, explicit text/image/link slot, original-text/attribute guards, non-overlapping partial text ranges, declared repeat container/prototype/items/count, preservation of unbound differences and verified SiteGraph identities. The CLI provides structural/site-catalog checks; only DOM execution can prove selector matches and repeat equivalence. Distinguish structural validation, semantic intent review and target-runtime/visual verification. Never translate a structural pass into an accuracy percentage. `/redo` of a failed graph replaces only the graph and report, never the approved HTML/CSS/JS.

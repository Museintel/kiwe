# /audit and /redo

Audit is read-only. Inspect the supplied artifact against its accepted source, declared contract and available rendered evidence. Report concrete findings with severity, selector or element ID, breakpoint, expected result, observed result and evidence. Scores must be reproducible from findings, not intuition.

For `/redo`, treat the last accepted artifact as authority and the failed output only as evidence. Re-run the responsible stage, close each proven failure and report unresolved items. Do not stack speculative patches on top of the failed artifact and do not redesign unrelated areas.

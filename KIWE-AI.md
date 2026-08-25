# SEAM and Kiwe AI boundary

SEAM is the public design, audit and conversion product. Kiwe is the WordPress runtime, SiteGraph authority and optional AppShell/commerce/security layer. They share contracts but do not duplicate responsibilities.

Start at `https://start.kiwelaunch.com/start.md`. The current contract exposes exactly six commands:

- `/ideate`
- `/convert /bricks`
- `/audit`
- `/accessibility`
- `/fix`
- `/redo`

Unknown commands and remembered aliases are rejected. SiteGraph and Design Context are attached or connected inputs, not commands. Seam Framework is an explicit option on Bricks conversion, not a command lane.

## Authority

- The user and their design conversation own creative direction.
- SEAM Compiler alone emits production Bricks JSON.
- Kiwe owns site facts, dynamic binding validation, native runtime actions and optional Framework installation.
- Browser AI may ideate or explain; it never receives generic WordPress save authority.
- External SiteGraph credentials are scoped, hashed, revocable, rate-limited and read-only. The only apply output available remotely is a dry-run plan.

## WordPress AI

Kiwe has one credential-blind broker over the configured WordPress AI provider. Optional internal services may use bounded service profiles; they do not store separate provider keys. These services are disabled by default and are not part of the SEAM conversion path.

## Release checks

```text
npm test --prefix kiwe-ai-toolkit
node tools/release/build-command-registry.cjs --check
node tools/release/verify-green-baseline.cjs
```

The generated public registry under `public/start.kiwelaunch.com` contains only the current contract and its single immutable release. Do not edit it manually.

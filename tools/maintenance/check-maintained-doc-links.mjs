import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const files = ['docs/GUEST-MENU-NOTIFICATION-COVERAGE-2026-09-10.md', 'docs/WORKSPACE-CARD-CONTROLS-2026-09-10.md', 'docs/FULL-WORKSPACE-UI-2026-09-10.md', 'docs/METRO-WORKSPACE-CORRECTION-2026-09-10.md', 'docs/WORKSPACE-SHELL-RC83-2026-09-10.md', 'docs/DESIGN-CONTEXT-WORKSPACE-COVERAGE.md', 'docs/design-context-native-records.md', 'docs/TILE-WORKSPACE-DIRECTION-2026-09-10.md', 'docs/decisions/2026-09-10-tile-workspace-shell.md', 'tools/workspace-prototype/README.md', 'docs/CLIENT-LAB-SETUP-2026-09-10.md', 'docs/RETENTION-DEPLOYMENT-RC554-2026-09-10.md', 'docs/SECURETRACK-RETENTION-RC82-2026-09-10.md', 'docs/TRAFFIC-RELIABILITY-RC81-2026-09-10.md', 'docs/SECURETRACK-TELEMETRY-RC80-2026-09-10.md', 'docs/ASCENDANTS-TRAFFIC-2026-09-09.md', 'docs/FIRST-LAUNCH-PLAN.md', 'docs/PWA-LIFECYCLE-RC77-2026-09-09.md', 'docs/decisions/2026-09-09-pwa-cache-isolation.md', 'docs/SECURETRACK-DEPLOYMENT-2026-09-08.md', 'docs/SECURETRACK-SCHEMA-RC69-2026-09-08.md', 'docs/decisions/2026-09-08-explicit-securetrack-schema-maintenance.md', 'docs/ASCENDANTS-INSTALL-SCREEN-2026-09-08.md', 'docs/WORKSPACE-ORGANIZATION-RC68-2026-09-08.md', 'docs/WORKSPACE-NOTICES-RC67-2026-09-08.md', 'docs/WORKSPACE-TASKS-RC66-2026-09-08.md', 'docs/ASCENDANTS-PUBLISHING-INCIDENT-2026-09-08.md', 'docs/WORKSPACE-APP-RC65-2026-09-08.md', 'docs/RC64-LAB-ACCEPTANCE-2026-09-07.md', 'docs/SEO-CHECKLIST-RC63-2026-09-06.md', 'docs/decisions/2026-09-06-bounded-seo-checklist.md', 'docs/SEO-JOBS-RC62-2026-09-06.md', 'docs/decisions/2026-09-06-bounded-seo-jobs.md', 'docs/APP-EXPERIENCE-ARCHITECTURE.md', 'docs/AI-TOKEN-ALLOWANCE-RC61-2026-09-06.md', 'docs/decisions/2026-09-06-ai-token-settlement.md', 'docs/AI-FEATURE-PERMISSIONS-RC60-2026-09-06.md', 'docs/decisions/2026-09-06-client-ai-permissions.md', 'AGENTS.md', 'HANDOFF.md', 'docs/PRODUCT-VISION.md', 'docs/FOUNDER-INTENT-REGISTER.md', 'docs/CLIENT-WORKSPACE-TASKS.md', 'docs/RESERVE-TASKS.md', 'docs/MAINTAINER-CONTRACT.md', 'docs/ENGINEERING-ROADMAP.md', 'docs/WORKSPACE-ENGINEERING-2026-09-06.md', 'docs/CONTENT-OPERATIONS-BATCH-2026-09-06.md', 'docs/DESIGN-LIBRARY-NAVIGATION-2026-09-06.md', 'docs/decisions/2026-09-06-explicit-design-context-selection.md', 'docs/BACKUP-AND-MEDIA-STRATEGY.md', 'docs/KIWE-MU-DEVELOPER-HANDOFF.md', 'docs/INSTALL-HOSTINGER.md', 'docs/AI-AFFORDABILITY-SEO.md', 'docs/RC58-LAB-ACCEPTANCE-2026-09-06.md', 'docs/RELEASE-RUNBOOK.md', 'docs/AI-ALLOWANCE-RC59-2026-09-06.md', 'docs/decisions/2026-09-06-ai-request-allowance.md'];
let checked = 0, failed = 0;
for (const file of files) {
  if (!fs.existsSync(path.join(root, file))) { console.error(`FAIL missing maintained document: ${file}`); failed++; continue; }
  const markdown = fs.readFileSync(path.join(root, file), 'utf8').replace(/```[\s\S]*?```/g, '');
  for (const match of markdown.matchAll(/\[[^\]\n]*\]\(([^)\n]+)\)/g)) {
    const href = match[1].replace(/^<|>$/g, '').split('#')[0];
    if (!href || /^(?:[a-z]+:|\/\/)/i.test(href)) continue;
    const target = path.resolve(root, path.dirname(file), decodeURIComponent(href));
    checked++;
    if (!fs.existsSync(target)) { console.error(`FAIL broken link: ${file} -> ${href}`); failed++; }
  }
}
if (!failed) console.log(`PASS ${checked} relative links across ${files.length} maintained documents`);
process.exitCode = failed ? 1 : 0;

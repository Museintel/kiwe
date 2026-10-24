#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..', '..');
// Qualification receipts must also work in a fresh checkout with no prior runs.
fs.mkdirSync(path.join(root, 'docs', 'generated'), { recursive: true });
const failures = [];

function outputFor(result) {
	return [result.stdout, result.stderr]
		.filter(Boolean)
		.map((value) => String(value).trim())
		.filter(Boolean)
		.join('\n');
}

function run(label, script, args = [], options = {}) {
	const result = spawnSync(process.execPath, [script, ...args], {
		cwd: root,
		encoding: 'utf8',
		maxBuffer: 16 * 1024 * 1024,
	});
	const expectedFailure = Boolean(options.expectedFailure);
	const combinedOutput = outputFor(result);
	const failedAsDesigned = expectedFailure
		&& result.status !== null
		&& result.status !== 0
		&& !result.error
		&& (!options.failurePattern || options.failurePattern.test(combinedOutput));
	const passed = expectedFailure ? failedAsDesigned : result.status === 0;

	if (passed) {
		console.log(`PASS ${label}`);
		return;
	}

	const detail = result.error
		? result.error.message
		: combinedOutput || `Process exited with status ${String(result.status)}.`;
	failures.push({ label, detail });
	console.error(`FAIL ${label}`);
}

function filesUnder(relativeDirectory, extension) {
	const directory = path.join(root, relativeDirectory);
	const files = [];

	for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
		// Installed dependency trees are tested through their lockfile/build consumers.
		// Do not launch a syntax-check process for every third-party source file.
		if (entry.isDirectory() && entry.name === 'node_modules') continue;
		const absolute = path.join(directory, entry.name);
		if (entry.isDirectory()) {
			files.push(...filesUnder(path.relative(root, absolute), extension));
		} else if (entry.isFile() && entry.name.endsWith(extension)) {
			files.push(absolute);
		}
	}

	return files;
}

function checkSyntax(label, files, moduleInput = false) {
	const errors = [];

	for (const file of files) {
		const args = moduleInput ? ['--input-type=module', '--check'] : ['--check', file];
		const options = {
			cwd: root,
			encoding: 'utf8',
			maxBuffer: 4 * 1024 * 1024,
		};
		if (moduleInput) {
			options.input = fs.readFileSync(file, 'utf8');
		}
		const result = spawnSync(process.execPath, args, options);
		if (result.status !== 0) {
			errors.push(`${path.relative(root, file)}\n${outputFor(result)}`);
		}
	}

	if (errors.length === 0) {
		console.log(`PASS ${label} (${files.length} files)`);
		return;
	}

	failures.push({ label, detail: errors.join('\n\n') });
	console.error(`FAIL ${label}`);
}

const gates = [
	['Store App admission, dormant settings, bookmarks and authoring boundaries', 'tools/release/store-app-contracts.cjs'],
	['SEO App admission, late external providers and Core record privacy', 'tools/release/seo-app-contracts.cjs'],
	['Complete Core context and authoring flow independent of SEO', 'tools/release/context-seo-independence-contracts.cjs'],
	['Conditional Woo runtime admission and non-Woo shared services', 'tools/release/conditional-runtime-contracts.cjs'],
	['Invariant native Bricks loop styles and full-query recovery', 'tools/release/loop-style-contracts.cjs'],
	['Unified client App choices, native atomic writes and role boundaries', 'tools/release/app-settings-contracts.cjs'],
	['Native encryption preference, compatibility and large-import denial', 'tools/security/native-secret-preference-contracts.cjs'],
	['Compiler dependency reader, native merge and authoring boundaries', 'tools/release/compiler-import-contracts.cjs'],
	['Compiler source-runtime dependency declaration and native import admission', 'tools/runtime/source-runtime-dependency-contracts.cjs'],
	['Compiler-owned shared source bridge and native selector authority', 'tools/runtime/native-reuse-contracts.cjs'],
	['Versioned public metadata, deferred inspection and requested diagnostics', 'tools/runtime/lean-runtime-contracts.cjs'],
	['Canonical public catalog matches generated asset', 'tools/runtime/build-public-contract.cjs', ['--check']],
	['Shared private workspace CSS cascade, tokens and native dependencies', 'tools/release/workspace-foundation-contracts.cjs'],
	['Private native form inbox and owner boundaries', 'tools/release/forms-workspace-contracts.cjs'],
	['Legacy storefront shipping and native filter images', 'tools/release/national-compatibility-contracts.cjs'],
	['Command-only preview/Framework journey and complete reference', 'tools/release/command-journey-contracts.cjs'],
	['Public binding reference matches its native owner', 'tools/release/project-authoring-reference.cjs', ['--check']],
	['Selected native testimonial collection and privacy boundaries', 'tools/release/context-collection-contracts.cjs'],
	['Native public preview selection, accessible record consistency and unsafe URL denial', 'tools/release/test-public-preview.cjs'],
	['Explicit public Design Context bindings and exact preview markers', 'tools/release/design-context-bindings-contracts.cjs'],
	['Design Context SEO goal, automatic News discovery and scoped field links', 'tools/release/seo-context-journey-contracts.cjs'],
	['Core inbox and private saved-cart identity projections', 'tools/release/saved-cart-inbox-contracts.cjs'],
	['Shared sheet refresh, verification and backdrop continuity', 'tools/runtime/sheet-continuity-contracts.cjs'],
	['Checkout serial draft and stale response boundaries', 'tools/runtime/checkout-save-contracts.cjs'],
	['Role scoped notification renderer and explicit selections', 'tools/runtime/notification-preferences-render-contracts.cjs'],
	['Private role preference frontend continuity', 'tools/release/notification-preference-client-contracts.cjs'],
	['Private notification catalog and default native preferences', 'tools/release/notification-preference-journey-contracts.cjs'],
	['Actionable cart notifications, deduplicated popups and native checkout reminders', 'tools/release/actionable-cart-notifications-contracts.cjs'],
	['Private startup native snapshots and deferred cart cost boundaries', 'tools/release/runtime-startup-contracts.cjs'],
	['Cart native charge breakdown and shared compact summary', 'tools/release/cart-totals-contracts.cjs'],
	['Native option cache failure and lazy visitor identity boundaries', 'tools/release/request-cost-contracts.cjs'],
	['Runtime source integrity and interrupted-write refusal', 'tools/release/source-integrity-contracts.cjs'],
	['Shipping-class native lifecycle, disabled Woo and tax-provider boundaries', 'tools/release/shipping-class-lifecycle-contracts.cjs'],
	['Native product attributes, shared label recovery and disabled Woo boundaries', 'tools/release/product-attribute-contracts.cjs'],
	['Per-product choices and native variations with non-Woo and shared journey boundaries', 'tools/release/product-variation-contracts.cjs'],
	['Native order and offline payment actions, non-Woo and shared journey boundaries', 'tools/release/order-operation-contracts.cjs'],
	['Native refund guards, money and provider recovery boundaries', 'tools/release/order-refund-contracts.cjs'],
	['Native merchandising and cheap recommendation reads', 'tools/release/merchandising-contracts.cjs'],
	['COD final-phone policy and inert non-Woo cart controls', 'tools/release/checkout-policy-contracts.cjs'],
	['Native guest checkout and policy authority', 'tools/release/guest-checkout-contracts.cjs'],
	['Shared modal instant scroll and browser history restoration', 'tools/runtime/surface-scroll-restoration-contracts.cjs'],
	['React team source matches private assets', 'tools/workspace-prototype/build-native-team.cjs', ['--check']],
	['Native App settings, reusable install actions and adoption boundaries', 'tools/release/app-workspace-contracts.cjs'],
	['React team native authority and denial behavior', 'tools/release/team-app-contracts.cjs'],
	['Design Context journey contracts', 'tools/release/design-context-journey-contracts.cjs'],
	['Inherited media and native Trash contracts', 'tools/release/media-trash-contracts.cjs'],
	['Operational status, native visibility and scoped Trash contracts', 'tools/release/status-visibility-contracts.cjs'],
	['React identity source matches private assets', 'tools/workspace-prototype/build-native-identity.cjs', ['--check']],
	['React identity native authority and denial behavior', 'tools/release/identity-app-contracts.cjs'],
	['React continuity assets match source', 'tools/workspace-prototype/build-native-continuity.cjs', ['--check']],
	['React media task matches private assets', 'tools/workspace-prototype/build-native-media.cjs', ['--check']],
	['React backups task matches private assets', 'tools/workspace-prototype/build-backups.cjs', ['--check']],
	['Encrypted local snapshot and restore behavior', 'tools/release/backups-contracts.cjs'],
	['Native organization snapshot recovery boundaries', 'tools/release/organization-snapshot-contracts.cjs'],
	['Native account profile recovery boundaries', 'tools/release/account-profile-snapshot-contracts.cjs'],
	['Native Kiwe app-choice recovery and protected-state boundaries', 'tools/release/kiwe-configuration-snapshot-contracts.cjs'],
	['Backup unit coverage, expansion and native authority', 'tools/release/backup-unit-catalog-contracts.cjs'],
	['Independent private portable recovery staging', 'tools/release/portable-recovery-stage-contracts.cjs'],
	['Strict native table recovery grammar and private connection boundaries', 'tools/release/portable-table-definition-contracts.cjs'],
	['Inert serialized URL root and native prefix migration', 'tools/release/portable-recovery-migration-contracts.cjs'],
	['Native destination ownership and copied-state quarantine', 'tools/release/portable-recovery-ownership-contracts.cjs'],
	['Native Store recovery and copied-commerce pause', 'tools/release/store-recovery-contracts.cjs'],
	['Isolated activation review and destination configuration preservation', 'tools/release/portable-recovery-activation-contracts.cjs'],
	['Interrupted activation placement and bound journal recovery', 'tools/release/portable-recovery-interruption-contracts.cjs'],
	['Native content archive ownership and atomic import behavior', 'tools/release/content-archive-contracts.cjs'],
	['Resumable content selection, cipher, retry and draft recovery', 'tools/release/content-archive-job-contracts.cjs'],
	['Media cipher, chunk checksums, private storage and draft reference mapping', 'tools/release/media-archive-contracts.cjs'],
	['Native Bricks font faces and typed recovery mapping', 'tools/release/bricks-font-contracts.cjs'],
	['Bricks public dynamic tags and native taxonomy boundaries', 'tools/release/bricks-dynamic-tags-contracts.cjs'],
	['Native Bricks Kiwe SVG icons and selector/context boundaries', 'tools/release/bricks-icon-library-contracts.cjs'],
	['Bricks approved HTML restricted-save boundaries', 'tools/release/bricks-html-preservation-contracts.cjs'],
	['Public cart attributes and automatic Bricks integration', 'tools/release/public-cart-attributes-contracts.cjs'],
	['Typed Bricks image/template recovery boundaries', 'tools/release/bricks-design-recovery-contracts.cjs'],
	['Closed native Bricks design package boundaries', 'tools/release/bricks-design-package-contracts.cjs'],
	['Pages, library and business tile boundaries', 'tools/release/continuity-app-contracts.cjs'],
	['Reviewed Service plan conversion and guarded native recovery', 'tools/release/service-plan-conversion-contracts.cjs'],
	['Native Service writing history and contextual recovery', 'tools/release/service-history-contracts.cjs'],
	['Native revision timeline and Organize task boundary', 'tools/release/revision-timeline-contracts.cjs'],
	['Native feedback moderation and testimonial selection', 'tools/release/comment-moderation-contracts.cjs'],
	['React posts source matches private assets', 'tools/workspace-prototype/build-native-posts.cjs', ['--check']],
	['React posts native authority and denial behavior', 'tools/release/posts-app-contracts.cjs'],
	['React Guest, notification and commerce assets', 'tools/workspace-prototype/build-native-workflow.cjs', ['--check']],
	['Native Guest, notification and commerce task behavior', 'tools/release/workflow-app-contracts.cjs'],
	['Rendered workspace activity and native setup boundaries', 'tools/release/workspace-activity-contracts.cjs'],
	['Core notification actor isolation and consent boundaries', 'tools/release/core-notification-contracts.cjs'],
	['Native workspace callbacks are publicly callable', 'tools/release/workspace-route-contracts.cjs'],
	['MU update recovery and debug-off behavior', 'tools/release/update-recovery-contracts.cjs'],
	['Lean media writer and dashboard GET boundary', 'tools/release/lean-media-route-contracts.cjs'],
	['React taxonomy source matches private assets', 'tools/workspace-prototype/build-native-taxonomy.cjs', ['--check']],
	['React taxonomy native authority and denial behavior', 'tools/release/taxonomy-app-contracts.cjs'],
	['React dock source matches private assets', 'tools/workspace-prototype/build-native-dock.cjs', ['--check']],
	['Dock workspace permissions and shared native settings', 'tools/release/dock-workspace-contracts.cjs'],
	['Automatic checkout journey and disabled-store boundaries', 'tools/release/dsa-continuity-contracts.cjs'],
	['Dock Links navigation and checkout copy', 'tools/release/dock-links-contracts.cjs'],
	['React navigation source matches private assets', 'tools/workspace-prototype/build-native-navigation.cjs', ['--check']],
	['Shared client screen inventory and built geometry', 'tools/release/workspace-design-system-contracts.cjs'],
	['React navigation native authority and denial behavior', 'tools/release/navigation-app-contracts.cjs'],
	['Client feature and native surface retention inventory', 'tools/release/workspace-coverage-contracts.cjs'],
	['Native task shell boundary behavior', 'tools/release/workspace-shell-contracts.cjs'],
	['SecureTrack retention budget and failure behavior', 'tools/runtime/securetrack-retention-behavior.cjs'],
	['Owner permissions and client AI preferences', 'tools/release/ai-feature-contracts.cjs'],
	['Bounded SEO checklist behavior', 'tools/release/seo-score-contracts.cjs'],
	['Bounded SEO job behavior', 'tools/release/seo-job-contracts.cjs'],
	['Reversible Rank Math metadata migration', 'tools/release/seo-metadata-migration-contracts.cjs'],
	['Shared SEO snapshot and content archive compatibility', 'tools/release/seo-backup-compatibility-contracts.cjs'],
	['Reversible stored website SEO settings handover', 'tools/release/seo-settings-handover-contracts.cjs'],
	['Native SEO defaults output and sitemap boundaries', 'tools/release/seo-native-output-contracts.cjs'],
	['Structured SEO parsing and bounded redirect boundaries', 'tools/release/seo-structured-contracts.cjs'],
	['Website-owned search measurement boundaries', 'tools/release/search-performance-contracts.cjs'],
	['React per-object SEO source matches private assets', 'tools/workspace-prototype/build-native-seo-object.cjs', ['--check']],
	['Shared site AI allowance behavior', 'tools/release/ai-allowance-contracts.cjs'],
	['Client workspace ownership and authorization', 'tools/release/client-workspace-contracts.cjs'],
	['Bricks Templates and contextual Analytics authority', 'tools/release/template-analytics-contracts.cjs'],
	['Role-aware Kiwe Workspace admin shell', 'tools/release/workspace-admin-contracts.cjs'],
	['Shared workspace Home and reactive native page naming', 'tools/release/workspace-app-contracts.cjs'],
	['Native category workspace behavior', 'tools/release/category-workspace-contracts.cjs'],
	['Native team workspace behavior', 'tools/release/team-workspace-contracts.cjs'],
	['Explicit context selection and native navigation behavior', 'tools/release/context-navigation-contracts.cjs'],
	['Ordered MU-plugin uploader', 'tools/release/upload-mu-plugin-contracts.cjs'],
	['Guest contribution and role-aware notification contracts', 'tools/release/guest-notification-contracts.cjs'],
	['Consent-aware bounded notification campaigns', 'tools/release/notification-campaign-contracts.cjs'],
	['Product proposal notification behavior', 'tools/release/product-notification-contracts.cjs'],
	['Release package integrity', 'tools/release/verify-package.cjs'],
	['Seam adoption audit', 'tools/ui-theme/audit-seam-adoption.cjs'],
	['Runtime token purity audit', 'tools/ui-theme/audit-runtime-token-purity.cjs'],
	['Valid UI theme package fixture', 'tools/ui-theme/validate-package.cjs', ['tools/ui-theme/fixtures/valid']],
	['Invalid runtime bridge token fixture', 'tools/ui-theme/validate-package.cjs', ['tools/ui-theme/fixtures/invalid-runtime-bridge-token'], { expectedFailure: true, failurePattern: /runtime bridge token/i }],
	['Valid Framework profile fixture', 'kiwe-ai-toolkit/tools/validate-framework-profile.cjs', ['kiwe-ai-toolkit/fixtures/framework-profile-valid']],
	['Invalid Framework profile fixture', 'kiwe-ai-toolkit/tools/validate-framework-profile.cjs', ['kiwe-ai-toolkit/fixtures/framework-profile-invalid'], { expectedFailure: true, failurePattern: /unknown_root_key/ }],
	['SEAM compiler foundation contracts', 'packages/seam-compiler-core/test/compiler-foundation.cjs'],
	['SEAM visual proof contracts', 'packages/seam-visual-proof/test/visual-proof.cjs'],
	['Bricks compiler batch cleanup contracts', 'tools/bricks/compiler-batch-cleanup-contracts.cjs'],
	['Persistence maintenance contracts', 'tools/release/persistence-maintenance-contracts.cjs'],
	['Database and cache contracts', 'tools/release/database-cache-contracts.cjs'],
	['Shared AI broker contracts', 'tools/release/ai-broker-contracts.cjs'],
	['SiteGraph external-client contracts', 'tools/release/sitegraph-client-contracts.cjs'],
	['SiteGraph universal adapter contracts', 'tools/release/sitegraph-adapter-contracts.cjs'],
	['SiteGraph design-context contracts', 'tools/release/sitegraph-design-context-contracts.cjs'],
	['Owner onboarding contracts', 'tools/release/onboarding-contracts.cjs'],
	['Native Design Context behavior', 'tools/release/design-context-native-contracts.cjs'],
	['Owner handoff refinement contracts', 'tools/release/owner-handoff-refinement-contracts.cjs'],
	['Kiwe AI Toolkit smoke contracts', 'kiwe-ai-toolkit/tools/smoke-test.cjs'],
	['AI API source contracts', 'tools/connector/ai-api-contracts.cjs'],
	['RC4 security contracts', 'tools/security/rc4-contracts.cjs'],
	['Configured-origin runtime and mutation guards', 'tools/security/origin-checker-contracts.cjs'],
	['RC5 cache contracts', 'tools/cache/rc5-contracts.cjs'],
	['RC6 runtime contracts', 'tools/runtime/rc6-contracts.cjs'],
	['RC7 PWA contracts', 'tools/pwa/rc7-contracts.cjs'],
	['PWA worker behavior', 'tools/pwa/worker-behavior.cjs'],
	['RC8 runtime contracts', 'tools/runtime/rc8-contracts.cjs'],
	['RC10 compatibility contracts', 'tools/compatibility/rc10-contracts.cjs'],
	['RC11 WordPress 7 contracts', 'tools/wp7/rc11-contracts.cjs'],
	['RC12 release contracts', 'tools/release/rc12-contracts.cjs'],
	['RC13 search contracts', 'tools/certification/rc13-search-contracts.cjs'],
	['Search Surface availability contracts', 'tools/runtime/search-surface-contracts.cjs'],
	['Profile, Menu, and control geometry contracts', 'tools/runtime/profile-menu-geometry-contracts.cjs'],
	['Shared sheet shell and modality contracts', 'tools/runtime/sheet-layout-contracts.cjs'],
	['Cart AJAX and Bricks query-loop behavior', 'tools/runtime/cart-ajax-contracts.cjs'],
	['Authored module launcher behavior', 'tools/runtime/module-launcher-contracts.cjs'],
	['No-dock Surface remains invisible and attribute-launchable', 'tools/runtime/no-dock-surface-contracts.cjs'],
	['Mobile overlay and AdSense isolation contracts', 'tools/runtime/mobile-overlay-adsense-contracts.cjs'],
	['PhoneKey strict-flow contracts', 'tools/runtime/phonekey-strict-flow-contracts.cjs'],
	['PhoneKey progressive-verification contracts', 'tools/runtime/phonekey-progressive-verification-contracts.cjs'],
	['PhoneKey role and commerce contracts', 'tools/runtime/phonekey-role-commerce-contracts.cjs'],
	['PhoneKey WordPress identity-continuity contracts', 'tools/runtime/phonekey-wordpress-identity-contracts.cjs'],
	['PhoneKey route-resilience contracts', 'tools/runtime/phonekey-route-resilience-contracts.cjs'],
	['SecureTrack alert escalation contracts', 'tools/security/securetrack-alert-contracts.cjs'],
	['SecureTrack publish performance contracts', 'tools/runtime/securetrack-publish-performance-contracts.cjs'],
	['SecureTrack optional telemetry behavior', 'tools/runtime/securetrack-telemetry-behavior.cjs'],
	['SecureTrack ingestion and first-request accounting', 'tools/runtime/securetrack-ingestion-behavior.cjs'],
	['SecureTrack summary coverage and report behavior', 'tools/runtime/securetrack-reports-behavior.cjs'],
	['SecureTrack denial worker budget', 'tools/runtime/securetrack-worker-budget.cjs'],
	['SecureTrack explicit schema maintenance', 'tools/release/securetrack-schema-contracts.cjs'],
	['Signed MU-plugin update contracts', 'tools/release/kiwe-update-contracts.cjs'],
	['Independent App provider/signature/permission contracts', 'tools/release/apps-platform-contracts.cjs'],
];

for (const [label, script, args = [], options = {}] of process.argv.includes('--syntax-only') ? [] : gates) {
	run(label, script, args, options);
}

checkSyntax(
	'Runtime JavaScript syntax',
	filesUnder('wp-content/mu-plugins/dsa/assets/js', '.js'),
	true
);
checkSyntax('Tool JavaScript syntax', filesUnder('tools', '.cjs'));
checkSyntax('Maintenance JavaScript syntax', filesUnder('tools/maintenance', '.mjs'));
checkSyntax('Compiler package JavaScript syntax', filesUnder('packages', '.cjs'));

if (failures.length > 0) {
	console.error(`\nKiwe green baseline failed (${failures.length} gate${failures.length === 1 ? '' : 's'}):`);
	for (const failure of failures) {
		console.error(`\n[${failure.label}]\n${failure.detail}`);
	}
	process.exit(1);
}

console.log(process.argv.includes('--syntax-only') ? '\nKiwe authored-source syntax verified (behavior gates not rerun).' : '\nKiwe green baseline verified.');

#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.resolve(__dirname, '..', '..');
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
	['Kiwe AI Toolkit smoke contracts', 'kiwe-ai-toolkit/tools/smoke-test.cjs'],
	['AI API source contracts', 'tools/connector/ai-api-contracts.cjs'],
	['RC4 security contracts', 'tools/security/rc4-contracts.cjs'],
	['RC5 cache contracts', 'tools/cache/rc5-contracts.cjs'],
	['RC6 runtime contracts', 'tools/runtime/rc6-contracts.cjs'],
	['RC7 PWA contracts', 'tools/pwa/rc7-contracts.cjs'],
	['RC8 runtime contracts', 'tools/runtime/rc8-contracts.cjs'],
	['RC10 compatibility contracts', 'tools/compatibility/rc10-contracts.cjs'],
	['RC11 WordPress 7 contracts', 'tools/wp7/rc11-contracts.cjs'],
	['RC12 release contracts', 'tools/release/rc12-contracts.cjs'],
	['RC13 search contracts', 'tools/certification/rc13-search-contracts.cjs'],
];

for (const [label, script, args = [], options = {}] of gates) {
	run(label, script, args, options);
}

checkSyntax(
	'Runtime JavaScript syntax',
	filesUnder('wp-content/mu-plugins/dsa/assets/js', '.js'),
	true
);
checkSyntax('Tool JavaScript syntax', filesUnder('tools', '.cjs'));
checkSyntax('Compiler package JavaScript syntax', filesUnder('packages', '.cjs'));

if (failures.length > 0) {
	console.error(`\nKiwe green baseline failed (${failures.length} gate${failures.length === 1 ? '' : 's'}):`);
	for (const failure of failures) {
		console.error(`\n[${failure.label}]\n${failure.detail}`);
	}
	process.exit(1);
}

console.log('\nKiwe green baseline verified.');

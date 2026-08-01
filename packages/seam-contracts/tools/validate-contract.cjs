#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const { validateContract } = require('../lib/validator.cjs');

const [, , contract, input] = process.argv;
if (!contract || !input) {
	console.error('Usage: node validate-contract.cjs <contract-name-or-schema> <json-file>');
	process.exit(2);
}

try {
	const file = path.resolve(process.cwd(), input);
	const result = validateContract(contract, JSON.parse(fs.readFileSync(file, 'utf8')));
	console.log(JSON.stringify({ ...result, file }, null, 2));
	if (!result.ok) process.exit(1);
} catch (error) {
	console.error(error instanceof Error ? error.message : String(error));
	process.exit(2);
}

const fs = require('node:fs');
const path = require('node:path');

const packageRoot = path.resolve(__dirname, '..');
const manifest = JSON.parse(fs.readFileSync(path.join(packageRoot, 'manifest.json'), 'utf8'));

function typeMatches(value, type) {
	if (type === 'null') return value === null;
	if (type === 'array') return Array.isArray(value);
	if (type === 'object') return value !== null && typeof value === 'object' && !Array.isArray(value);
	if (type === 'integer') return Number.isInteger(value);
	if (type === 'number') return typeof value === 'number' && Number.isFinite(value);
	return typeof value === type;
}

function validateNode(schema, value, at, errors) {
	if (!schema || schema === true) return;
	if (schema === false) {
		errors.push({ path: at, code: 'forbidden', message: `${at} is not allowed.` });
		return;
	}

	if (Object.prototype.hasOwnProperty.call(schema, 'const') && value !== schema.const) {
		errors.push({ path: at, code: 'const', message: `${at} must equal ${JSON.stringify(schema.const)}.` });
		return;
	}
	if (schema.enum && !schema.enum.some((candidate) => candidate === value)) {
		errors.push({ path: at, code: 'enum', message: `${at} is not an allowed value.` });
		return;
	}

	const declaredTypes = Array.isArray(schema.type) ? schema.type : schema.type ? [schema.type] : [];
	if (declaredTypes.length && !declaredTypes.some((type) => typeMatches(value, type))) {
		errors.push({ path: at, code: 'type', message: `${at} must be ${declaredTypes.join(' or ')}.` });
		return;
	}

	if (typeof value === 'string') {
		if (schema.minLength !== undefined && value.length < schema.minLength) {
			errors.push({ path: at, code: 'minLength', message: `${at} is shorter than ${schema.minLength}.` });
		}
		if (schema.pattern && !new RegExp(schema.pattern).test(value)) {
			errors.push({ path: at, code: 'pattern', message: `${at} does not match ${schema.pattern}.` });
		}
	}

	if (typeof value === 'number') {
		if (schema.minimum !== undefined && value < schema.minimum) errors.push({ path: at, code: 'minimum', message: `${at} is below ${schema.minimum}.` });
		if (schema.maximum !== undefined && value > schema.maximum) errors.push({ path: at, code: 'maximum', message: `${at} is above ${schema.maximum}.` });
	}

	if (Array.isArray(value)) {
		if (schema.minItems !== undefined && value.length < schema.minItems) {
			errors.push({ path: at, code: 'minItems', message: `${at} must contain at least ${schema.minItems} item(s).` });
		}
		if (schema.items) value.forEach((item, index) => validateNode(schema.items, item, `${at}[${index}]`, errors));
		return;
	}

	if (value !== null && typeof value === 'object') {
		const properties = schema.properties || {};
		for (const key of schema.required || []) {
			if (!Object.prototype.hasOwnProperty.call(value, key)) {
				errors.push({ path: `${at}.${key}`, code: 'required', message: `${at}.${key} is required.` });
			}
		}
		for (const [key, item] of Object.entries(value)) {
			if (properties[key]) {
				validateNode(properties[key], item, `${at}.${key}`, errors);
			} else if (schema.additionalProperties === false) {
				errors.push({ path: `${at}.${key}`, code: 'additionalProperties', message: `${at}.${key} is not allowed.` });
			} else if (schema.additionalProperties && typeof schema.additionalProperties === 'object') {
				validateNode(schema.additionalProperties, item, `${at}.${key}`, errors);
			}
		}
	}
}

function contractRecord(nameOrSchema) {
	return manifest.contracts.find((record) => record.name === nameOrSchema || record.schema === nameOrSchema);
}

function loadSchema(nameOrSchema) {
	const record = contractRecord(nameOrSchema);
	if (!record) throw new Error(`Unknown SEAM contract: ${nameOrSchema}`);
	return { record, schema: JSON.parse(fs.readFileSync(path.join(packageRoot, record.file), 'utf8')) };
}

function validateContract(nameOrSchema, value) {
	const { record, schema } = loadSchema(nameOrSchema);
	const errors = [];
	validateNode(schema, value, '$', errors);
	return { ok: errors.length === 0, schema: record.schema, errors };
}

module.exports = { contractRecord, loadSchema, manifest, validateContract };

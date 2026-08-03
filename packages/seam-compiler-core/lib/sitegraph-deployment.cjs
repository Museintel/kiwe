const crypto = require('node:crypto');
const path = require('node:path');
const { assertContract } = require('./normalize-capture.cjs');

function asArray(value) {
	return Array.isArray(value) ? value : [];
}

function canonical(value) {
	if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
	if (value && typeof value === 'object') {
		return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`;
	}
	return JSON.stringify(value);
}

function digest(value) {
	return `sha256:${crypto.createHash('sha256').update(canonical(value)).digest('hex')}`;
}

function cleanString(value) {
	return String(value ?? '').trim();
}

function uniqueStrings(values) {
	return [...new Set(values.map(cleanString).filter(Boolean))].sort((left, right) => left.localeCompare(right));
}

function normalizeTag(value) {
	const tag = cleanString(typeof value === 'string' ? value : value && (value.name || value.tag));
	return tag ? (tag.startsWith('{') ? tag : `{${tag.replace(/[{}]/g, '')}}`) : '';
}

function sanitizeSiteGraph(siteGraph) {
	if (!siteGraph || siteGraph.schema !== 'kiwe.site-graph.v1') throw new Error('SiteGraph input must use kiwe.site-graph.v1.');
	const wordpress = siteGraph.wordpress || {};
	const bricks = siteGraph.bricks || {};
	const abilities = bricks.abilities || {};
	const modules = siteGraph.kiwe && siteGraph.kiwe.modules;
	const taxonomyMap = new Map();
	for (const item of asArray(wordpress.taxonomies)) {
		if (!item || !item.name) continue;
		taxonomyMap.set(cleanString(item.name), {
			name: cleanString(item.name), label: cleanString(item.label || item.name),
			terms: asArray(item.terms).filter((term) => Number.isInteger(Number(term.id))).map((term) => ({ id: Number(term.id), name: cleanString(term.name), slug: cleanString(term.slug) })).sort((a, b) => a.id - b.id)
		});
	}
	for (const [name, terms] of [['product_cat', siteGraph.woocommerce && siteGraph.woocommerce.productCategories], ['product_tag', siteGraph.woocommerce && siteGraph.woocommerce.productTags]]) {
		if (taxonomyMap.has(name) || !asArray(terms).length) continue;
		taxonomyMap.set(name, { name, label: name === 'product_cat' ? 'Product categories' : 'Product tags', terms: asArray(terms).map((term) => ({ id: Number(term.id), name: cleanString(term.name), slug: cleanString(term.slug) })).filter((term) => Number.isInteger(term.id)).sort((a, b) => a.id - b.id) });
	}
	const moduleItems = modules && typeof modules === 'object' ? asArray(modules.items || modules.modules || modules.registered) : [];
	const snapshot = {
		schema: 'seam.sitegraph-snapshot.v1', sourceSchema: 'kiwe.site-graph.v1', sourceHash: `sha256:${'0'.repeat(64)}`,
		authority: { readOnly: true, mayMutateWordPress: false, secretsIncluded: false },
		wordpress: {
			postTypes: asArray(wordpress.postTypes).filter((item) => item && item.name).map((item) => ({ name: cleanString(item.name), label: cleanString(item.label || item.name), public: Boolean(item.public) })).sort((a, b) => a.name.localeCompare(b.name)),
			taxonomies: [...taxonomyMap.values()].sort((a, b) => a.name.localeCompare(b.name))
		},
		bricks: {
			active: Boolean(bricks.active), version: cleanString(bricks.version),
			queryLoopTypes: uniqueStrings(asArray(bricks.queryLoopTypes).map((item) => item && (item.objectType || item.name))),
			dynamicTags: uniqueStrings([...asArray(bricks.dynamicTags), ...asArray(bricks.kiweDynamicTags)].map(normalizeTag)),
			trustedAdapterLikelyAvailable: Boolean(abilities.mcpLikelyAvailable || (abilities.wpAbilitiesApiPresent && abilities.bricksAbilityManager))
		},
		kiwe: { version: cleanString(siteGraph.kiwe && siteGraph.kiwe.version), modules: uniqueStrings(moduleItems.map((item) => item && (item.id || item.module || item.key))) }
	};
	snapshot.sourceHash = digest({ sourceSchema: snapshot.sourceSchema, wordpress: snapshot.wordpress, bricks: snapshot.bricks, kiwe: snapshot.kiwe });
	assertContract('siteGraphSnapshot', snapshot);
	return snapshot;
}

function slug(value, fallback) {
	return cleanString(value).toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || fallback;
}

function selectorFor(node) {
	const attributes = node.attributes || {};
	if (attributes.id) return `#${attributes.id}`;
	return cleanString(node.provenance && node.provenance.selector) || `[data-seam-node='${node.id}']`;
}

function parseJsonObject(value) {
	if (!cleanString(value)) return { value: {}, valid: true };
	try {
		const parsed = JSON.parse(value);
		return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? { value: parsed, valid: true } : { value: {}, valid: false };
	} catch {
		return { value: {}, valid: false };
	}
}

function dynamicTagsIn(value, result = new Set()) {
	if (typeof value === 'string') for (const match of value.matchAll(/\{[^{}]+\}/g)) result.add(match[0]);
	else if (Array.isArray(value)) for (const item of value) dynamicTagsIn(item, result);
	else if (value && typeof value === 'object') for (const item of Object.values(value)) dynamicTagsIn(item, result);
	return result;
}

function buildBindingPlan(capture, snapshot) {
	const queries = [];
	const dynamicFields = [];
	const launchers = [];
	const menuContext = [];
	const review = [];
	const postTypes = new Set(snapshot.wordpress.postTypes.map((item) => item.name));
	const queryTypes = new Set(snapshot.bricks.queryLoopTypes);
	const dynamicTags = new Set(snapshot.bricks.dynamicTags);
	const modules = new Set(snapshot.kiwe.modules);
	const taxonomies = new Map(snapshot.wordpress.taxonomies.map((item) => [item.name, new Set(item.terms.map((term) => term.id))]));
	const queryIds = new Set();

	for (const node of capture.nodes) {
		const attributes = node.attributes || {};
		if (attributes['data-kiwe-binding']) {
			const id = slug(attributes['data-kiwe-binding'], `query-${queries.length + 1}`);
			if (queryIds.has(id)) {
				review.push({ nodeId: node.id, reason: `Duplicate query binding id ${id}; only the first declaration was compiled.` });
			} else {
				queryIds.add(id);
				const objectType = cleanString(attributes['data-kiwe-object-type'] || 'post');
				const postType = cleanString(attributes['data-kiwe-post-type']);
				const count = Number(attributes['data-kiwe-posts-per-page'] || 6);
				const bricks = { objectType, posts_per_page: Number.isInteger(count) && count >= 1 && count <= 50 ? count : 6 };
				if (postType) bricks.post_type = [postType];
				const rawTaxQuery = uniqueStrings(cleanString(attributes['data-kiwe-tax-query']).split(','));
				const taxQuery = rawTaxQuery.filter((value) => /^[a-z0-9_-]+::\d+$/i.test(value));
				if (taxQuery.length) bricks.tax_query = taxQuery;
				const parsedBindings = parseJsonObject(attributes['data-kiwe-bindings']);
				queries.push({ id, label: cleanString(attributes['aria-label'] || node.accessibleName || id), selector: `[data-kiwe-binding='${cleanString(attributes['data-kiwe-binding'])}']`, bricks, bindings: parsedBindings.value });
				if (!queryTypes.has(objectType)) review.push({ nodeId: node.id, reason: `Query loop type ${objectType} is absent from the SiteGraph.` });
				if (objectType === 'post' && !postType) review.push({ nodeId: node.id, reason: 'Post query binding requires an explicit data-kiwe-post-type.' });
				if (postType && !postTypes.has(postType)) review.push({ nodeId: node.id, reason: `Post type ${postType} is absent from the SiteGraph.` });
				if (!Number.isInteger(count) || count < 1 || count > 50) review.push({ nodeId: node.id, reason: 'data-kiwe-posts-per-page must be an integer from 1 to 50; safe default 6 was used.' });
				if (taxQuery.length !== rawTaxQuery.length) review.push({ nodeId: node.id, reason: 'One or more taxonomy filters did not use taxonomy::term_id and were omitted.' });
				for (const filter of taxQuery) {
					const [taxonomy, termId] = filter.split('::');
					if (!taxonomies.has(taxonomy) || !taxonomies.get(taxonomy).has(Number(termId))) review.push({ nodeId: node.id, reason: `Taxonomy term ${filter} is absent from the SiteGraph.` });
				}
				if (!parsedBindings.valid) review.push({ nodeId: node.id, reason: 'data-kiwe-bindings must be a JSON object; invalid binding data was omitted.' });
				for (const tag of dynamicTagsIn(parsedBindings.value)) if (!dynamicTags.has(tag)) review.push({ nodeId: node.id, reason: `Binding dynamic tag ${tag} is absent from the SiteGraph.` });
			}
		}
		if (attributes['data-kiwe-dynamic-tag']) {
			const tag = normalizeTag(attributes['data-kiwe-dynamic-tag']);
			dynamicFields.push({ selector: selectorFor(node), field: cleanString(attributes['data-kiwe-dynamic-field'] || 'text'), tag });
			if (!dynamicTags.has(tag)) review.push({ nodeId: node.id, reason: `Dynamic tag ${tag} is absent from the SiteGraph.` });
		}
		if (attributes['data-dsa-open-module']) {
			const value = cleanString(attributes['data-dsa-open-module']);
			launchers.push({ selector: `[data-dsa-open-module='${value}']`, attribute: 'data-dsa-open-module', value });
			if (!modules.has(value)) review.push({ nodeId: node.id, reason: `Kiwe module ${value} is absent from the SiteGraph.` });
		}
		if (attributes['data-kiwe-menu-label']) {
			const id = cleanString(attributes.id);
			if (id && asArray(node.observations).some((observation) => observation.visible)) menuContext.push({ label: cleanString(attributes['data-kiwe-menu-label']), selector: `#${id}`, id, source: 'visible-section' });
			else if (id) review.push({ nodeId: node.id, reason: 'Menu context must reference a visible element in at least one captured viewport.' });
			else review.push({ nodeId: node.id, reason: 'Menu context requires a stable visible element id.' });
		}
	}
	const plan = {
		schema: 'kiwe.bricks-bindings.v1', siteGraphSchema: 'kiwe.site-graph.v1',
		target: { builder: 'bricks', mode: 'binding-plan', applyAuthority: 'human-or-kiwe-adapter' },
		queries, dynamicFields, launchers, menuContext, assumptions: [], requiresHumanReview: review
	};
	assertContract('bindings', plan);
	return plan;
}

function extensionFor(asset) {
	try {
		const extension = path.posix.extname(new URL(asset.source, 'https://seam.invalid/').pathname).toLowerCase();
		return /^\.[a-z0-9]{1,8}$/.test(extension) ? extension : '';
	} catch {
		return '';
	}
}

function buildAssetImportPlan(manifest) {
	const operations = manifest.assets.map((asset) => {
		let action = asset.policy;
		let status = asset.policy === 'blocked' ? 'blocked' : asset.policy === 'review' ? 'review-required' : 'planned';
		const preconditions = [];
		let target = null;
		if (asset.policy === 'import') {
			if (!asset.contentHash || !asset.mime || asset.bytes === null) {
				action = 'review'; status = 'review-required';
				preconditions.push('Resolve bytes, MIME type, and SHA-256 content hash before import.');
			} else {
				target = `media/seam/${asset.contentHash.slice(7, 23)}${extensionFor(asset)}`;
				preconditions.push('Verify downloaded bytes match contentHash before media-library import.');
			}
		} else if (asset.policy === 'external') preconditions.push('Confirm external origin is allowed by the target site policy.');
		else if (asset.policy === 'inline') preconditions.push('Revalidate inline payload MIME and byte budget.');
		else if (asset.policy === 'blocked') preconditions.push('Do not fetch or import this resource.');
		else preconditions.push('A human must select import, external, inline, or blocked policy.');
		return { id: `asset:${asset.id}`, assetId: asset.id, action, source: asset.source, contentHash: asset.contentHash, mime: asset.mime, bytes: asset.bytes, target, status, preconditions, rollback: target ? `Delete only the newly created attachment whose source hash is ${asset.contentHash}.` : 'No mutation is authorized by this plan.' };
	});
	const plan = {
		schema: 'seam.asset-import-plan.v1', sourceHash: manifest.sourceHash, mode: 'dry-run', mutatesWordPress: false, operations,
		summary: { total: operations.length, planned: operations.filter((item) => item.status === 'planned').length, reviewRequired: operations.filter((item) => item.status === 'review-required').length, blocked: operations.filter((item) => item.status === 'blocked').length }
	};
	assertContract('assetImportPlan', plan);
	return plan;
}

function buildDeploymentPlan(sourceHash, snapshot, assetPlan, bindings) {
	const unresolvedAssets = assetPlan.summary.reviewRequired + assetPlan.summary.blocked;
	const unresolvedBindings = bindings.requiresHumanReview.length;
	const operations = [
		{ id: 'assets:content-addressed-import', type: 'kiwe.assets.import-plan', status: unresolvedAssets ? 'blocked' : 'planned', inputArtifact: 'assets/import-plan.json', executorBoundary: 'Future media adapter; not accepted by the current staging executor.' },
		{ id: 'bricks:template-create', type: 'bricks.template.create', status: 'planned', inputArtifact: 'bricks/templates/content.json', executorBoundary: 'Existing Kiwe Staging_Execution_Service after explicit admin confirmations.' },
		{ id: 'bricks:bindings-apply', type: 'kiwe.bricks.bindings', status: unresolvedBindings ? 'blocked' : 'planned', inputArtifact: 'bindings/kiwe-bindings.json', executorBoundary: 'Future trusted binding adapter after selector-to-element resolution and preview.' }
	];
	const plan = {
		schema: 'seam.deployment-plan.v1', sourceHash, siteGraphHash: snapshot.sourceHash,
		target: { builder: 'bricks', mode: 'dry-run-deployment-plan', authority: 'admin-approved-kiwe-staging-executor', stagingOnly: true, mutatesWordPress: false },
		safety: { requiresFreshSiteGraph: true, requiresRevision: true, requiresExplicitConfirmation: true, publishes: false },
		preflight: ['Re-fetch kiwe.site-graph.v1 and require the same sourceHash.', 'Validate every package integrity hash.', 'Create a restorable Bricks/WordPress revision.', 'Resolve every blocked plan operation.', 'Require explicit staging-site and raw-Bricks-write confirmations.'],
		operations,
		rollback: { strategy: 'restore-pre-apply-revision-and-delete-new-content-addressed-assets', requiredEvidence: ['pre-apply revision id', 'created post/template ids', 'created attachment ids and hashes', 'staging execution result'] },
		limitations: ['This artifact never mutates WordPress.', 'It is not a staging execution request.', 'Publishing is outside this plan.', 'Asset and binding adapters remain blocked until their executor contracts are implemented and validated.']
	};
	assertContract('deploymentPlan', plan);
	return plan;
}

module.exports = { buildAssetImportPlan, buildBindingPlan, buildDeploymentPlan, canonical, digest, sanitizeSiteGraph };

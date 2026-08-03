const { createHash } = require('node:crypto');
const { assertContract } = require('../../seam-compiler-core/lib/normalize-capture.cjs');

function serializeBricksTemplate(plan, title = 'SEAM compiled page') {
	assertContract('bricksPlan', plan);
	if (plan.aiGenerated !== false || plan.compiler !== 'seam-compiler') {
		const error = new Error('Only deterministic SEAM Compiler plans may be serialized to Bricks JSON.');
		error.code = 'SEAM_BRICKS_PLAN_AUTHORITY_REJECTED';
		throw error;
	}
	const planHash = `sha256:${createHash('sha256').update(JSON.stringify(plan)).digest('hex')}`;
	return {
		title,
		templateType: 'content',
		version: plan.target.bricksVersion,
		content: plan.elements.map((element) => ({
			id: element.id,
			name: element.type,
			parent: element.parentId || 0,
			children: element.children,
			settings: element.settings
		})),
		global_classes: plan.globalClasses,
		globalVariables: plan.variables,
		pageSettings: plan.customCss ? { customCss: plan.customCss } : {},
		kiwe: {
			schema: 'kiwe.bricks-template.v1',
			target: { importMethod: 'kiwe-staging-executor', bricksVersion: plan.target.bricksVersion },
			provenance: { planSchema: plan.schema, sourceHash: plan.sourceHash, planHash },
			frameworkProfile: {
				path: '../../framework/kiwe-framework-profile.json',
				projectVariables: plan.variables.map((variable) => `--${String(variable.name).replace(/^--/, '')}`)
			}
		},
		generator: {
			name: 'SEAM Compiler',
			planSchema: plan.schema,
			planHash,
			sourceHash: plan.sourceHash,
			bricksVersion: plan.target.bricksVersion,
			aiDirectJson: false
		}
	};
}

module.exports = { serializeBricksTemplate };

class UnsupportedAiDirectJsonError extends Error {
	constructor() {
		super('AI-authored Bricks JSON is not a supported SEAM Compiler input. Supply rendered capture evidence and compile through typed IR.');
		this.name = 'UnsupportedAiDirectJsonError';
		this.code = 'SEAM_AI_DIRECT_JSON_UNSUPPORTED';
	}
}

function compileAiDirectJson() {
	throw new UnsupportedAiDirectJsonError();
}

module.exports = { compileAiDirectJson, UnsupportedAiDirectJsonError };

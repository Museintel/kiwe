const tokenCatalog = require('../../seam-contracts/generated/kiwe-token-catalog.json');
const KIWE_VERSION = tokenCatalog.pluginVersion;

const SLOT_TOKENS = {
	siteBackground: 'color-surface',
	colorPrimary: 'color-brand',
	colorSecondary: 'color-accent',
	colorLight: 'color-surface-raised',
	colorSurfaceRaised: 'color-surface-raised',
	colorDark: 'color-text',
	colorMuted: 'color-text-muted',
	colorBorder: 'color-border',
	fontDisplay: 'font-display',
	fontBody: 'font-body',
	typeH1: 'type-h1',
	typeBody: 'type-body',
	spaceMd: 'space-md',
	radiusLg: 'radius-lg',
	shadowMd: 'shadow-md'
};

function safeName(value) {
	return String(value || 'seam-page').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'seam-page';
}

function buildFrameworkProfile(title) {
	const bricksThemeStyle = { enabled: true, id: `seam-${safeName(title)}`, label: `${title} SEAM Framework` };
	for (const [slot, tokenName] of Object.entries(SLOT_TOKENS)) {
		const token = tokenCatalog.tokens[tokenName];
		if (!token) throw new Error(`Missing required Kiwe token ${tokenName} in generated catalog.`);
		bricksThemeStyle[slot] = token.value;
	}
	return {
		type: 'kiwe-framework-profile', schema: 'kiwe.framework-profile.v1', schemaVersion: 1, pluginVersion: KIWE_VERSION,
		source: { siteName: title },
		settings: {
			tokens: {
				enabled: true, profile_label: `${title} SEAM Framework`, overrides: {},
				bricks_theme_style: bricksThemeStyle
			}
		}
	};
}

module.exports = { buildFrameworkProfile, KIWE_VERSION, SLOT_TOKENS };

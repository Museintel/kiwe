const fs = require('node:fs');

const CANDIDATES = process.platform === 'win32'
	? [
		'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
		'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
		'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
		'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'
	]
	: process.platform === 'darwin'
		? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge']
		: ['/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/microsoft-edge'];

function findBrowser(explicitPath = process.env.SEAM_CHROME_PATH) {
	if (explicitPath) {
		if (!fs.existsSync(explicitPath)) throw new Error(`SEAM browser executable does not exist: ${explicitPath}`);
		return explicitPath;
	}
	const match = CANDIDATES.find((candidate) => fs.existsSync(candidate));
	if (!match) throw new Error('No Chromium browser found. Install Chrome/Edge/Chromium or set SEAM_CHROME_PATH.');
	return match;
}

module.exports = { CANDIDATES, findBrowser };

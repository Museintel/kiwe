const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'tools/release/upload-mu-plugin-tus.mjs'), 'utf8');

function check(label, condition) {
	if (!condition) {
		console.error(`FAIL: ${label}`);
		process.exitCode = 1;
		return;
	}
	console.log(`PASS: ${label}`);
}

check('uploader accepts multiple include arguments', source.includes('...includeArguments') && source.includes('includeArguments.flatMap'));
check('uploader still accepts comma-separated includes', source.includes("argument.split(',')"));
check('nested package uploads before root MU loaders', source.includes('await uploadBatch(packageFiles);') && source.includes('await uploadBatch(loaderFiles, 1);'));
check('both root loader files are protected', source.includes("new Set(['dsa.php', 'kiwe-incident-guard.php'])"));

if (!process.exitCode) console.log('MU uploader contracts verified.');

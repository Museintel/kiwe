const {execFileSync}=require('node:child_process');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
const fs=require('node:fs');
const version=fs.readFileSync(path.join(root,'wp-content/mu-plugins/dsa/dsa.php'),'utf8').match(/define\(\s*'DSA_VERSION',\s*'([^']+)'/);if(!version||!fs.readFileSync(path.join(root,'CHANGELOG.md'),'utf8').includes('## '+version[1]+' '))throw Error('Document the current candidate before generating release metadata');
for(const script of ['tools/release/build-apps-ownership.cjs','packages/seam-contracts/tools/generate-types.cjs','tools/release/commerce-source-inventory.cjs','tools/release/build-package-manifest.cjs'])process.stdout.write(execFileSync(process.execPath,[path.join(root,script)],{cwd:root,encoding:'utf8',windowsHide:true}));

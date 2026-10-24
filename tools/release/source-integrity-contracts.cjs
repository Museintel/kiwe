const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {assertTextIntegrity}=require('./source-integrity.cjs');
assert.throws(()=>assertTextIntegrity(Buffer.alloc(32578),'.php','interrupted PHP'),/Null-filled/);
assert.throws(()=>assertTextIntegrity(Buffer.from([0xff]),'.js','invalid source'),/Invalid UTF-8/);
assert.throws(()=>assertTextIntegrity(Buffer.from('empty runtime'),'.php','missing code'),/opening tag/);
assert.equal(assertTextIntegrity(Buffer.from("<?php\r\n// Valid Unicode: पुणे\r\n"),'.php','valid PHP').includes('पुणे'),true);
const root=path.resolve(__dirname,'../..');let count=0;
const extensions=new Set(['.php','.jsx','.js','.mjs','.cjs','.json','.css','.html','.svg','.xml','.txt','.md']);
function scan(dir){for(const entry of fs.readdirSync(dir,{withFileTypes:true})){const file=path.join(dir,entry.name);if(entry.isDirectory())scan(file);else if(entry.isFile()&&extensions.has(path.extname(file))){assertTextIntegrity(fs.readFileSync(file),path.extname(file),path.relative(root,file));count++;}}}
for(const folder of ['wp-content/mu-plugins','tools/release'])scan(path.join(root,folder));
console.log(JSON.stringify({passed:true,checks:4,sourceFiles:count,scope:'runtime and release tools',nullFilledPhpRefused:true}));

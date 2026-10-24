const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const repo=path.resolve(__dirname,'../..'),ownership=JSON.parse(fs.readFileSync(path.join(__dirname,'apps-ownership.json'),'utf8'));
const files=Object.values(ownership.apps).flatMap(a=>a.files).sort();assert.equal(files.length,new Set(files).size,'An App file must have one owner');
for(const file of files)assert(fs.statSync(path.join(repo,'wp-content/mu-plugins/dsa',file)).isFile(),file);
for(const file of ownership.shared.commerceExceptions)assert(!files.includes(file),'General reporting/security stay in Core');
const target=path.join(repo,'wp-content/mu-plugins/dsa/includes/Apps/Package_Layout.php');
const text=`<?php\nnamespace DSA\\Apps;\nif (!defined('ABSPATH')) exit;\n\n/** Generated from tools/release/apps-ownership.json. Build profiles share one source owner. */\nfinal class Package_Layout {\n public const BUNDLED = true; // KIWE_APPS_BUNDLED_PROFILE\n public const MINIMUM = [${Object.entries(ownership.apps).map(([id,app])=>"'"+id+"'=>'"+app.minimumVersion+"'").join(',')}];\n public const APP_FILES = [\n${files.map(f=>"  '"+f+"',").join('\n')}\n ];\n}\n`;
if(process.argv.includes('--check'))assert.equal(fs.readFileSync(target,'utf8').replace(/\r\n?/g,'\n'),text,'App/Core ownership map is stale');else fs.writeFileSync(target,text);
console.log(JSON.stringify({passed:true,files:files.length,owners:Object.keys(ownership.apps)}));

const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),nodeCrypto=require('node:crypto');
const repo=path.resolve(__dirname,'../..'),source=path.join(repo,'packages/kiwe-mcp-runtime/vendor'),target=path.join(repo,'wp-content/mu-plugins/dsa/vendor/mcp');
const lock=JSON.parse(fs.readFileSync(path.join(repo,'packages/kiwe-mcp-runtime/composer.lock'),'utf8'));
const expected={'automattic/jetpack-autoloader':'v6.0.3','wordpress/mcp-adapter':'v0.7.1','wordpress/php-mcp-schema':'v0.2.0'};
assert.deepEqual(Object.fromEntries(lock.packages.map(p=>[p.name,p.version])),expected,'Dependencies must match the reviewed exact lock');
const isolatedAutoload = String.raw`<?php
// Kiwe loads only its private dependency namespaces. Other plugins own theirs.
spl_autoload_register(static function(string $class): void {
    $prefixes = [
        'DSA\\MCP\\Vendor\\WP\\MCP\\' => __DIR__ . '/wordpress/mcp-adapter/includes/',
        'DSA\\MCP\\Vendor\\WP\\McpSchema\\' => __DIR__ . '/wordpress/php-mcp-schema/src/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $relative = substr($class, strlen($prefix));
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $relative)) return;
        $file = $directory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) require_once $file;
        return;
    }
});
`;
function isolate(relative,text) {
 if(relative==='autoload.php')return isolatedAutoload;
 if(!relative.endsWith('.php'))return text;
 return text.replace(/WP\\(MCP|McpSchema)(?=\\|\b)/g,'DSA\\MCP\\Vendor\\WP\\$1')
  .replace(/WP\\\\(MCP|McpSchema)(?=\\\\|\b)/g,'DSA\\\\MCP\\\\Vendor\\\\WP\\\\$1')
  .replace(/\bmcp_adapter_(?!sessions\b)([a-z_]+)/g,'kiwe_mcp_transport_$1')
  .replace(/\bwp_mcp_init\b/g,'kiwe_mcp_transport_plugin_init')
  .replace(/\bWP_MCP_/g,'KIWE_TRANSPORT_MCP_');
}
function withoutComposerRootReference(body) {
 let text=body.toString('utf8');
 const references=[
  /(^    'root' => array\(\n        'name' => 'kiwe\/mcp-runtime',\n(?:        '(?:pretty_version|version)' => '[^'\n]*',\n){2}        'reference' => )(?:'[a-f0-9]{40}'|null),$/m,
  /(^        'kiwe\/mcp-runtime' => array\(\n(?:            '(?:pretty_version|version)' => '[^'\n]*',\n){2}            'reference' => )(?:'[a-f0-9]{40}'|null),$/m,
 ];
 for(const reference of references){
  assert(reference.test(text),'Expected Composer root package reference');
  text=text.replace(reference,'$1null,');
 }
 return text;
}
function walk(root,prefix=''){const result=[];for(const e of fs.readdirSync(root,{withFileTypes:true})){const relative=prefix+e.name;assert(!e.isSymbolicLink(),'No vendor symlinks');if(e.isDirectory())result.push(...walk(path.join(root,e.name),relative+'/'));else if(e.isFile())result.push(relative);else throw Error('Unsupported vendor member');}return result.sort();}
const files=walk(source),pins={};assert(files.length<400,'Optional runtime file budget');
const checkOnly=process.argv.includes('--check');
for(const relative of files){assert(/^[A-Za-z0-9_][A-Za-z0-9_/.\-]*$/.test(relative)&&!relative.split('/').some(p=>p.startsWith('.')),'Safe vendor path '+relative);
 const bytes=fs.readFileSync(path.join(source,relative));assert(!bytes.includes(0),'Only dependency text is shipped');assert.deepEqual(Buffer.from(bytes.toString('utf8')),bytes,'Valid original UTF-8 dependency bytes');
 let body=Buffer.from(isolate(relative,bytes.toString('utf8').replace(/\r\n?/g,'\n')));
 const dest=path.join(target,relative);
 if(checkOnly){
  const shipped=fs.readFileSync(dest);
  // Composer records the local Git checkout twice for the root package.
  // Check the rest against Composer and keep the signed bytes for the receipt.
  if(relative==='composer/installed.php'&&!shipped.equals(body)){
   assert(withoutComposerRootReference(shipped)===withoutComposerRootReference(body),'Composer package metadata drift');
   body=shipped;
  }
  assert.deepEqual(shipped,body,relative);
 }else{
  fs.mkdirSync(path.dirname(dest),{recursive:true});
  fs.writeFileSync(dest,body);
 }
 pins[relative]={bytes:body.length,sha256:nodeCrypto.createHash('sha256').update(body).digest('hex')};
}
assert.deepEqual(walk(target),files,'No unowned vendor members');
const receipt={schema:'kiwe.mcp-vendor.v1',isolation:'kiwe-transport-namespace-v1',composerLockSha256:nodeCrypto.createHash('sha256').update(fs.readFileSync(path.join(repo,'packages/kiwe-mcp-runtime/composer.lock'))).digest('hex'),dependencies:expected,files:pins};
const manifest=JSON.stringify(receipt,null,2)+'\n',receiptPath=path.join(repo,'packages/kiwe-mcp-runtime/vendor-manifest.json');
if(checkOnly)assert.equal(fs.readFileSync(receiptPath,'utf8'),manifest);else fs.writeFileSync(receiptPath,manifest);
console.log(JSON.stringify({passed:true,files:files.length,dependencies:expected,ordinaryRenderAutoload:false}));

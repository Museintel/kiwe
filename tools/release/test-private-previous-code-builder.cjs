'use strict';
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),crypto=require('node:crypto'),cp=require('node:child_process'),assert=require('node:assert/strict');
const repo=path.resolve(__dirname,'../..'),sandbox=fs.mkdtempSync(path.join(os.tmpdir(),'kiwe-previous-code-builder-')),checks={};
const signing=JSON.parse(fs.readFileSync(path.join(repo,'docs/generated/kiwe-woo-target-private-signing-rc306-a.json'))),release=JSON.parse(fs.readFileSync(signing.releaseManifest));
const publicPem=crypto.generateKeyPairSync('rsa',{modulusLength:2048}).publicKey.export({type:'spki',format:'pem'});fs.writeFileSync(path.join(sandbox,'public.pem'),publicPem);fs.writeFileSync(path.join(sandbox,'release.json'),JSON.stringify(release));
const defaults={'source-root':'/home/u123456789/domains/source.example/public_html','execution-root':'/home/u123456789/domains/lab.example/public_html','id':'kiwe-private-original-test','source-version':'8.0.0-rc.10','public-key':path.join(sandbox,'public.pem'),'release-manifest':path.join(sandbox,'release.json'),'output':path.join(sandbox,'kiwe-private-test.php')};
const invoke=(override={})=>cp.spawnSync(process.execPath,[path.join(__dirname,'build-private-previous-code-review.cjs'),...Object.entries({...defaults,...override}).map(([k,v])=>'--'+k+'='+v)],{cwd:repo,encoding:'utf8',windowsHide:true});
try{
 const good=invoke();assert.equal(good.status,0,good.stderr);const proof=JSON.parse(good.stdout);assert.equal(proof.sourceFilesReadOnly,true);assert.equal(proof.wordpressBooted,false);assert.equal(proof.databaseConnected,false);assert.equal(crypto.createHash('sha256').update(fs.readFileSync(defaults.output)).digest('hex'),proof.sha256);checks['valid separate operator emits an exact checksum-bound helper without source execution']=true;
 const fail=(name,override)=>{const target=path.join(sandbox,'kiwe-failed-'+Object.keys(checks).length+'.php'),result=invoke({output:target,...override});assert.notEqual(result.status,0,name);assert.equal(fs.existsSync(target),false,name);checks[name]=true;};
 fail('source and executor cannot be the same website',{'execution-root':defaults['source-root']});
 fail('operator cannot borrow another hosting account',{'execution-root':'/home/u987654321/domains/lab.example/public_html'});
 fail('source traversal refuses',{'source-root':'/home/u123456789/domains/source.example/../public_html'});
 fail('executor outside explicit document root refuses',{'execution-root':'/home/u123456789/domains/lab.example/private'});
 fail('unsafe owner identifier refuses',{id:'../kiwe-vault'});
 fail('non-version source declaration refuses',{'source-version':'old version'});
 fail('non-PHP or unsafe public helper name refuses',{output:path.join(sandbox,'helper.txt')});
 const ec=crypto.generateKeyPairSync('ec',{namedCurve:'prime256v1'}).publicKey.export({type:'spki',format:'pem'});fs.writeFileSync(path.join(sandbox,'ec.pem'),ec);fail('non-RSA custody key refuses',{'public-key':path.join(sandbox,'ec.pem')});
 const small=crypto.generateKeyPairSync('rsa',{modulusLength:1024}).publicKey.export({type:'spki',format:'pem'});fs.writeFileSync(path.join(sandbox,'small.pem'),small);fail('undersized custody key refuses',{'public-key':path.join(sandbox,'small.pem')});
 for(const[field,value,label]of [['schema','unknown','unrecognized release schema refuses'],['sha256','not-a-hash','unbound archive digest refuses'],['packageManifestSha256','not-a-hash','unbound inner manifest refuses'],['bytes',12,'invalid release byte bound refuses']]){const p=path.join(sandbox,'bad-'+field+'.json');fs.writeFileSync(p,JSON.stringify({...release,[field]:value}));fail(label,{'release-manifest':p});}
 const repeated=invoke();assert.notEqual(repeated.status,0);assert.equal(crypto.createHash('sha256').update(fs.readFileSync(defaults.output)).digest('hex'),proof.sha256);checks['existing immutable helper cannot be overwritten']=true;
 const php=process.env.KIWE_PHP;if(php){const lint=cp.spawnSync(php,['-l',defaults.output],{encoding:'utf8',windowsHide:true});assert.equal(lint.status,0,lint.stderr);checks['packed helper passes native PHP parsing']=true;}
 console.log(JSON.stringify({schema:'kiwe.private-previous-code-builder-tests.v1',passed:true,cleanup:true,checks,sourceRuntimeChanged:false,sourceExecuted:false,remoteWrites:0}));
}finally{const actual=fs.realpathSync(sandbox),parent=fs.realpathSync(os.tmpdir());assert.equal(path.dirname(actual),parent);assert(path.basename(actual).startsWith('kiwe-previous-code-builder-'));fs.rmSync(actual,{recursive:true});}

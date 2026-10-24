const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const root=path.resolve(__dirname,'../..'),dir='wp-content/mu-plugins/dsa/includes/Secure/';
for(const args of [[],['cli']]){const r=spawnSync('php',['tools/release/test-securetrack-schema.php',...args],{cwd:root,encoding:'utf8'});process.stdout.write(r.stdout||'');if(r.status!==0||/Warning:|Deprecated:|Fatal error:/.test(r.stdout+r.stderr)){process.stderr.write(r.stderr||'');process.exit(1);}}
for(const file of ['SecureTrack_Db_Service.php','SecureTrack_Event_Service.php','securetrack-core.php','securetrack-admin-core.php']){const r=spawnSync('php',['-l',dir+file],{cwd:root,encoding:'utf8'});if(r.status!==0)throw Error(r.stdout+r.stderr);}
const core=fs.readFileSync(path.join(root,dir,'securetrack-core.php'),'utf8'),event=fs.readFileSync(path.join(root,dir,'SecureTrack_Event_Service.php'),'utf8'),admin=fs.readFileSync(path.join(root,dir,'securetrack-admin-core.php'),'utf8');
if(event.includes('stp_repair_database('))throw Error('Logging must not request schema repair');
for(const name of ['stp_create_tables','stp_migrate_schema'])if(!core.includes(`function ${name}() {\n\tif ( ! \\DSA\\Secure\\SecureTrack_Db_Service::schema_write_allowed() ) return;`))throw Error('Schema function missing guard');
if(admin.includes('?page=stp-settings&stp_do=repairdb'))throw Error('Schema maintenance must not be a GET link');
const definitions=[...core.matchAll(/dbDelta\(\s*"CREATE TABLE " \. stp_t\( '(\w+)' \) \. " \((.*?)\) \$cs;" \);/gs)];
if(definitions.length!==10)throw Error('Expected all ten definitions');
for(const d of definitions)for(const line of d[2].split('\n').map(s=>s.trim()).filter(Boolean)){
 if(/^(PRIMARY|UNIQUE|KEY)\s/.test(line))continue;
 if(!/^\w+ (?:bigint\(20\)|int\(11\)|smallint\(6\)|tinyint\(1\)|varchar\(\d+\)|longtext|text|datetime) /.test(line))throw Error('Schema formatting can cause repeated dbDelta ALTER: '+d[1]);
}
console.log('PASS schema coordinator syntax and compatibility entry-point guards');

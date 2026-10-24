const{spawnSync}=require('node:child_process'),fs=require('node:fs'),assert=require('node:assert/strict');
const r=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-bricks-font-boundaries.php'],{encoding:'utf8',windowsHide:true});
if(r.status!==0||/Warning:|Fatal error:|Deprecated:/.test(r.stdout+r.stderr))throw Error(r.stdout+r.stderr);
const proof=JSON.parse(r.stdout);assert(proof.passed&&Object.values(proof.checks).every(Boolean));
const font=fs.readFileSync('wp-content/mu-plugins/dsa/includes/Backup/Bricks_Font_Archive_Service.php','utf8');
assert(font.includes('Media_Archive_Service::capture(')&&font.includes('wp_insert_post(')&&font.includes('Custom_Fonts::get_custom_fonts()'));
assert(!/CREATE TABLE|ALTER TABLE|extractTo|file_put_contents|wp_ajax_|wp_schedule/.test(font));
console.log(JSON.stringify({passed:true,checks:Object.keys(proof.checks).length,nativeOwners:true,sharedMediaJobs:true}));

const{spawnSync}=require('node:child_process'),fs=require('node:fs'),assert=require('node:assert/strict');
const result=spawnSync(process.env.KIWE_PHP||'php',['tools/release/test-comment-moderation.php'],{encoding:'utf8',windowsHide:true});
if(result.status!==0||/Warning:|Fatal error:|Deprecated:/.test(result.stdout+result.stderr))throw Error(result.stdout+result.stderr);console.log(result.stdout.trim());
const service=fs.readFileSync('wp-content/mu-plugins/dsa/includes/Access/Comment_Moderation_Service.php','utf8');
assert(service.includes('wp_set_comment_status')&&service.includes('Context_Selection_Service')&&service.includes("'number'=>self::PAGE_SIZE + 1"));
assert(!/comment_author_email|CREATE TABLE|ALTER TABLE|wp_schedule|set_transient/.test(service));
const ui=fs.readFileSync('tools/workspace-prototype/native-continuity.jsx','utf8');
for(const text of ['Feedback types','Product reviews','Mark as testimonial','Testimonial selection never edits'])assert(ui.includes(text),text);
console.log('Feedback UI uses bounded native moderation and the shared Design Context selector.');

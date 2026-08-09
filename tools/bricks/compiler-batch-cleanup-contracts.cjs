#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const service = read('wp-content/mu-plugins/dsa/includes/Bricks/Compiler_Batch_Cleanup_Service.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

check('compiler batches use exact five-character namespace recognition', service.includes("'/^seam-([a-z0-9]{5})-/'") && service.includes("'/^seam-[a-z0-9]{5}-$/'"));
check('name shape alone is never compiler ownership proof', service.includes('NAMESPACE_REGISTRY_OPTION') && service.includes("t.slug = 'seam-compiler'") && service.includes("'seam-compiler-template'") && service.includes("'class-source'"));
check('Framework-owned Seam utilities cannot enter the compiler registry', service.includes('$framework_owned') && service.includes('! $framework_owned'));
check('cleanup mutates only registered compiler namespaces', service.includes("! isset( $recognized[ $namespace ] ) || $keep_namespace === $namespace") && service.includes('The keep namespace has no SEAM Compiler ownership evidence.'));
check('live Bricks content references protect class IDs', service.includes("'_cssGlobalClasses'") && service.includes("isset( $referenced_ids[ $id ] )"));
check('cleanup excludes trashed, auto-draft, inherited, and revision content', service.includes("p.post_status NOT IN ('trash','auto-draft','inherit')") && service.includes("p.post_type <> 'revision'"));
check('cleanup requires the keep namespace to exist', service.includes("The keep namespace does not exist in the active Bricks global classes."));
check('cleanup captures a recoverable registry backup', service.includes("dsa_bricks_compiler_cleanup_backup") && service.includes("'active_classes' => $classes") && service.includes("'trash_classes'  => $trash"));
check('removed classes enter Bricks trash with deletion timestamps', service.includes("$class['deletedAt'] = time();") && service.includes('BRICKS_DB_GLOBAL_CLASSES_TRASH'));
check('Bricks CSS regeneration uses the native scheduler with a compatible fallback', service.includes('Bricks\\Assets_Files::schedule_css_file_regeneration()') && service.includes("wp_schedule_single_event( time() + 1, 'bricks_regenerate_css_files' )"));
check('Framework ownership excludes only evidence-backed compiler batches', admin.includes('Compiler_Batch_Cleanup_Service::is_recognized_compiler_class( $class )') && admin.includes('return false;'));
check('Developer cleanup requires capability, nonce, and confirmation', admin.includes("current_user_can( 'manage_options' )") && admin.includes("check_admin_referer( 'dsa_developer_cleanup_bricks_batches' )") && admin.includes("$_POST['confirm_cleanup']"));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} Bricks compiler cleanup contracts passed.`);
if (failed.length) process.exit(1);

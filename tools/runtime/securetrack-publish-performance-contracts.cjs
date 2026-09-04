const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const core = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-core.php');
const brain = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-site-brain.php');
const database = read('wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Db_Service.php');

let passed = 0;
const checks = [];
function check(name, condition) {
  checks.push({ name, condition: Boolean(condition) });
  if (condition) passed += 1;
}

const contentHooks = core.slice(
  core.indexOf('//  POST / CONTENT HOOKS'),
  core.indexOf("add_action( 'wp_trash_post'")
);
const queueMaybe = brain.slice(
  brain.indexOf('function stp_ai_queue_maybe('),
  brain.indexOf('function stp_ai_status(')
);

check('post persistence produces one SecureTrack content audit',
  contentHooks.includes("add_action( 'wp_after_insert_post'") &&
  !contentHooks.includes("add_action( 'save_post'") &&
  !contentHooks.includes("add_action( 'post_updated'") &&
  !contentHooks.includes("add_action( 'transition_post_status'") &&
  (contentHooks.match(/stp_log\(/g) || []).length === 1
);

check('the consolidated audit retains field and status evidence',
  contentHooks.includes("'post_title' => 'title'") &&
  contentHooks.includes("'post_status' => 'status'") &&
  contentHooks.includes("'post_content' => 'content'") &&
  contentHooks.includes("$extra['before_status']") &&
  contentHooks.includes("$extra['after_status']")
);

check('always AI mode is priority background work, never an inline provider call',
  queueMaybe.includes('stp_ai_schedule_priority_queue();') &&
  !queueMaybe.includes('stp_ai_process_queue_item(') &&
  brain.includes("wp_schedule_single_event( time() + 1, 'stp_cron_ai_queue_priority'") &&
  core.includes("add_action( 'stp_cron_ai_queue_priority', 'stp_run_priority_ai_queue' )")
);

check('AI burst scheduling is coalesced and retains the regular fallback',
  brain.includes("wp_next_scheduled( 'stp_cron_ai_queue_priority' )") &&
  brain.includes('stp_ai_process_pending_queue( 3 );') &&
  core.includes("wp_schedule_event( time() + 60, 'stp_5min', 'stp_cron_ai_queue' )")
);

check('schema existence checks are memoized per request and reset for schema writes',
  database.includes('private static array $table_existence = [];') &&
  database.includes('array_key_exists( $name, self::$table_existence )') &&
  (database.match(/self::\$table_existence = \[\];/g) || []).length >= 3
);

for (const item of checks) {
  console.log(`${item.condition ? 'PASS' : 'FAIL'} ${item.name}`);
}
console.log(`\n${passed}/${checks.length} SecureTrack publish performance contracts passed.`);
if (passed !== checks.length) process.exit(1);

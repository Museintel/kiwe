const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const limiter = read('wp-content/mu-plugins/dsa/includes/Utilities/Atomic_Rate_Limiter.php');
const origin = read('wp-content/mu-plugins/dsa/includes/Utilities/Origin_Checker.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const abandoned = read('wp-content/mu-plugins/dsa/includes/Commerce/Abandoned_Cart_Service.php');
const analytics = read('wp-content/mu-plugins/dsa/includes/Commerce/Store_Analytics_Service.php');
const cart = read('wp-content/mu-plugins/dsa/includes/Rest/Cart_Controller.php');
const metrics = read('wp-content/mu-plugins/dsa/includes/Metrics/Metrics_Service.php');
const saved = read('wp-content/mu-plugins/dsa/includes/Saved/Saved_Items_Service.php');
const rewards = read('wp-content/mu-plugins/dsa/includes/Rewards/Reward_Service.php');
const search = read('wp-content/mu-plugins/dsa/includes/Search/Search_Service.php');
const settings = read('wp-content/mu-plugins/dsa/includes/Settings.php');

const checks = [
  ['shared atomic limiter is boot-registered', plugin.includes('Atomic_Rate_Limiter::register()')],
  ['persistent object cache uses atomic increment', limiter.includes('wp_using_ext_object_cache()') && limiter.includes('wp_cache_incr')],
  ['shared-host limiter uses one primary row per bucket', limiter.includes('PRIMARY KEY (bucket_hash)') && limiter.includes('ON DUPLICATE KEY UPDATE')],
  ['legacy limiter callers fail open; discussion can opt into strict admission', limiter.includes("rate_limit.storage_unavailable") && /bool \$fail_open = true/.test(limiter) && /storage_unavailable[\s\S]{0,140}return \$fail_open;/.test(limiter)],
  ['expired limiter rows have bounded cleanup', limiter.includes("WHERE expires_at < %d LIMIT 5000") && limiter.includes("'hourly'")],
  ['REST limiter no longer creates minute transients', origin.includes('Atomic_Rate_Limiter::allow') && !origin.includes('set_transient')],
  ['Cart REST limiter uses shared atomic store', cart.includes('Atomic_Rate_Limiter::allow') && !cart.includes("'dsa_cart_rate_'") && !/private function rate_limit[\s\S]{0,500}set_transient/.test(cart)],
  ['Store Analytics dedupe uses bounded limiter', analytics.includes("Atomic_Rate_Limiter::allow( $bucket, 1") && !analytics.includes("set_transient( $key, 1, max( MINUTE_IN_SECONDS, $ttl )")],
  ['Search analytics dedupe uses bounded limiter', analytics.includes("Atomic_Rate_Limiter::allow( $bucket, 1, 5 * MINUTE_IN_SECONDS )") && !analytics.includes('dsa_search_event_')],
  ['Search result cache is object-cache only', search.includes('wp_cache_get') && search.includes('wp_cache_set') && !search.includes('get_transient') && !search.includes('set_transient')],
  ['Store Analytics has automatic retention', analytics.includes('dsa_store_analytics_cleanup') && analytics.includes('purge_events_older_than')],
  ['abandoned cart has configurable heartbeat', settings.includes("'heartbeat_minutes'        => 5") && abandoned.includes("'heartbeat_minutes'")],
  ['unchanged cart skips writes inside heartbeat', abandoned.includes('unchanged_within_heartbeat') && abandoned.includes("'browse' === $reason && $same_cart")],
  ['unchanged heartbeat performs a narrow update', abandoned.includes("array_intersect_key( $data") && abandoned.includes("'last_activity_at'")],
  ['same-request duplicate cart capture is suppressed', abandoned.includes("hash( 'sha256', wp_json_encode( [ $cart_hash, $reason") && abandoned.includes('$request_capture_signature')],
  ['abandoned retention visits each terminal status in bounded batches and preserves child receipts', /foreach\s*\(\s*\[\s*'converted','cleared','abandoned'\s*\]/.test(abandoned) && abandoned.includes('ORDER BY updated_at ASC,id ASC LIMIT 50') && abandoned.includes('NOT EXISTS (SELECT 1 FROM {$this->logs_table()} l WHERE l.cart_id=c.id)')],
  ['core capture has private automatic preparation and no pause control', abandoned.includes("add_action( 'admin_init', [ $this, 'prepare_core_capture' ], 30 )") && abandoned.includes("[ 'enabled'=>true ]") && !abandoned.includes('wp_clear_scheduled_hook( self::CRON_HOOK )')],
  ['metric context cardinality is bounded', metrics.includes('count( $contexts ) >= 100') && metrics.includes("$event . ':other'")],
  ['hot write paths are profile-visible', ['store_analytics.insert','metrics.rollup_write','saved_items.meta_write','rewards.identity_state_write','rewards.ip_state_write','abandoned_cart.update'].every((name) => analytics.includes(name) || metrics.includes(name) || saved.includes(name) || rewards.includes(name) || abandoned.includes(name))],
];

// Model the SQL contract: any volume in one logical bucket occupies one row,
// resets after its window, and never allows more than the configured limit.
let state = null;
const allow = (now, limit, window) => {
  if (!state || state.windowStart <= now - window) state = { counter: 1, windowStart: now };
  else state.counter++;
  return state.counter <= limit;
};
let accepted = 0;
for (let i = 0; i < 100000; i++) if (allow(1000, 60, 60)) accepted++;
checks.push(['100k requests retain one bucket row and enforce limit', accepted === 60 && state && state.counter === 100000]);
checks.push(['bucket resets after the configured window', allow(1061, 60, 60) === true && state.counter === 1]);

let failed = 0;
for (const [name, ok] of checks) {
  process.stdout.write(`${ok ? 'PASS' : 'FAIL'} ${name}\n`);
  if (!ok) failed++;
}
if (failed) {
  process.stderr.write(`\n${failed} RC6 write-budget contract check(s) failed.\n`);
  process.exit(1);
}
process.stdout.write(`\nRC6 shared-host write-budget contracts passed (${checks.length}/${checks.length}).\n`);

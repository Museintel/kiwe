#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const service = read('wp-content/mu-plugins/dsa/includes/Diagnostics/Persistence_Maintenance_Service.php');
const manifest = read('wp-content/mu-plugins/dsa/includes/Runtime/Package_Manifest.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');
const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

check('inventory explicitly distinguishes database rows from filesystem inodes', service.includes("'databaseRowsAreNotInodes'       => true") && admin.includes('Database rows can increase database size and query cost, but they are not hosting inodes.'));
check('current DSA SecureTrack and PhoneKey schemas are enumerated', service.includes("'dsa_store_events'") && service.includes("'stp_events'") && service.includes("'pk_credentials'"));
check('legacy-table deletion is restricted to current-site Kiwe table prefixes', service.includes("'(?:dsa|stp|pk)_[A-Za-z0-9_]+$/'") && service.includes('! $this->is_owned_table_name( $table ) || $this->is_current_table( $table )'));
check('safe maintenance preserves PhoneKey credentials and factors', service.includes("$wpdb->prefix . 'pk_challenges'") && service.includes("$wpdb->prefix . 'pk_trusted_devices'") && !service.includes("DELETE FROM `{$wpdb->prefix}pk_credentials`") && !service.includes("DELETE FROM `{$wpdb->prefix}pk_factors`"));
check('safe maintenance never targets WordPress or WooCommerce content tables', !/DROP TABLE[^\n]+(?:posts|users|orders|woocommerce)/i.test(service) && !/DELETE FROM[^\n]+(?:posts|users|orders|woocommerce)/i.test(service));
check('package manifest reports unexpected merged-upload files', manifest.includes('public static function unexpected_files(): array') && service.includes('Package_Manifest::unexpected_files()'));
check('possible top-level old MU copies are report-only', service.includes('mu_plugin_residue_inventory') && admin.includes('filename similarity alone is not ownership proof'));
check('destructive actions require capability nonce checkbox and exact phrase', admin.includes("check_admin_referer( 'dsa_developer_drop_legacy_tables' )") && admin.includes("'CLEAN LEGACY TABLES' !== $phrase") && admin.includes("check_admin_referer( 'dsa_developer_remove_orphan_files' )") && admin.includes("'REMOVE ORPHAN FILES' !== $phrase"));
check('reset returns to SiteGraph-only baseline', admin.includes('$this->settings->fresh_install_defaults()'));
check('disabled modules do not register persistence installers', plugin.includes('if ( $phonekey_enabled )') && plugin.includes('if ( $push_enabled )') && plugin.includes('if ( $analytics_enabled )') && plugin.includes('if ( $abandoned_enabled )'));
check('Bricks registration ignores non-feature metadata strings', plugin.includes("! empty( $bricks['dynamic_tags_enabled'] )") && !plugin.includes('$bricks_enabled    = (bool) array_filter( $bricks )'));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} persistence maintenance contracts passed.`);
if (failed.length) process.exit(1);

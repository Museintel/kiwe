#!/usr/bin/env node
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const broker = read('wp-content/mu-plugins/dsa/includes/AI/AI_Broker_Service.php');
const provider = read('wp-content/mu-plugins/dsa/includes/AI/AI_Provider_Service.php');
const wp7 = read('wp-content/mu-plugins/dsa/includes/WP7/AI_Client_Service.php');
const admin = read('wp-content/mu-plugins/dsa/includes/Admin/Admin.php');
const securetrack = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-site-brain.php');
const securetrackAdmin = read('wp-content/mu-plugins/dsa/includes/Secure/securetrack-admin-core.php');
const securetrackPolicy = read('wp-content/mu-plugins/dsa/includes/Secure/SecureTrack_Settings_Policy.php');
const plugin = read('wp-content/mu-plugins/dsa/includes/Plugin.php');

const checks = [];
const check = (name, pass) => checks.push({ name, pass: Boolean(pass) });

check('SiteGraph is reachable from the unified Context destination', admin.includes("[ 'Context', 'kiwe-context', 'render_context_page' ]") && admin.includes("[ 'kiwe-sitegraph', 'render_site_graph_page'"));
check('AI page sends SiteGraph operators to the dedicated control plane', admin.includes('SiteGraph has its own control plane') && admin.includes('admin.php?page=kiwe-sitegraph'));
check('SiteGraph actions return to the SiteGraph control plane', admin.includes("'page'           => 'kiwe-sitegraph'") && admin.includes("admin.php?page=kiwe-sitegraph"));
check('broker has separate Studio SiteGraph SecureTrack and Bricks profiles', ['studio', 'sitegraph', 'securetrack', 'bricks'].every((id) => broker.includes(`'${id}' => [`)));
check('broker rejects shared or implicit memory', broker.includes('shared_or_implicit_memory_forbidden') && broker.includes("isset( $request['history'] )") && broker.includes("isset( $request['messages'] )"));
check('broker enforces service capability prompt and rate boundaries', broker.includes('capability_not_allowed') && broker.includes('prompt_budget_exceeded') && broker.includes('service_rate_limited'));
check('broker validates output contracts and does not audit raw prompts', broker.includes('output_contract_failed') && broker.includes("'promptHash'") && !broker.includes("'prompt'        =>"));
check('provider key remains behind the transport and broker profiles expose no secret', provider.includes('Secret_Store::decrypt') && broker.includes("'secretAccess'  => false"));
check('Gemini defaults use the current production-stable model', provider.includes("'gemini'            => 'gemini-3.7-flash'") && admin.includes('placeholder="gemini-3.7-flash / gpt-4.1-mini"') && securetrackPolicy.includes("'gemini-3.7-flash'"));
check('WordPress 7 native AI Client is invoked through the official prompt entry point', wp7.includes("function_exists( 'wp_ai_client_prompt' )") && wp7.includes('wp_ai_client_prompt( $user )') && wp7.includes('generate_text()'));
check('SecureTrack cloud review calls the broker', securetrack.includes('new \\DSA\\AI\\AI_Broker_Service') && securetrack.includes("'service'    => 'securetrack'") && securetrack.includes("'capability' => 'classify_security'"));
check('SecureTrack contains no direct provider HTTP call', !securetrack.includes('wp_remote_post') && !securetrack.includes('wp_remote_get'));
check('SecureTrack does not persist a duplicate AI secret', securetrackAdmin.includes("'v2_ai_key_enc'        => ''") && !securetrackAdmin.includes('stp_encrypt_secret( $stored_ai_key )'));
check('legacy SecureTrack AI secrets are removed once during migration', securetrackPolicy.includes("'stp_ai_broker_secret_cleanup_v1'") && securetrackPolicy.includes("$stored_settings['v2_ai_key_enc'] = ''"));
check('approved integrations can discover broker without receiving credentials', plugin.includes("do_action( 'kiwe_ai_broker_ready', $this->ai_broker )") && broker.includes("apply_filters( 'kiwe_ai_broker_profiles', $profiles )"));

for (const item of checks) console.log(`${item.pass ? 'PASS' : 'FAIL'} ${item.name}`);
const failed = checks.filter((item) => !item.pass);
console.log(`\n${checks.length - failed.length}/${checks.length} AI broker contracts passed.`);
if (failed.length) process.exit(1);

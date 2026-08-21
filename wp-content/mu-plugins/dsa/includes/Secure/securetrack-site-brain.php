<?php

if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

//  SECURETRACK v2 SITE BRAIN  (local-first learning + AI queue)
// ════════════════════════════════════════════════════════════════

function stp_brain_feature_key( $type, $value ) {
	$value = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $value ) ) );
	$type = substr( sanitize_key( $type ), 0, 48 );
	return $type . ':' . hash( 'sha256', $type . "\0" . $value );
}

function stp_brain_path_bucket( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	$path = '/' . trim( $path ?: '/', '/' );
	if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|xmlrpc\.php)(?:/|$)#i', $path, $m ) ) return strtolower( $m[1] );
	$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );
	if ( ! $parts ) return '/';
	return '/' . sanitize_title( $parts[0] ) . ( ! empty( $parts[1] ) && ! preg_match( '/^\d+$/', $parts[1] ) ? '/' . sanitize_title( $parts[1] ) : '' );
}

function stp_brain_ua_family_from_string( $ua ) {
	$ua = strtolower( sanitize_text_field( (string) $ua ) );
	if ( $ua === '' ) return 'missing';
	if ( strpos( $ua, 'chrome' ) !== false ) return 'chrome';
	if ( strpos( $ua, 'firefox' ) !== false ) return 'firefox';
	if ( strpos( $ua, 'safari' ) !== false ) return 'safari';
	if ( strpos( $ua, 'edge' ) !== false || strpos( $ua, 'edg/' ) !== false ) return 'edge';
	if ( preg_match( '/bot|crawl|spider|slurp/i', $ua ) ) return 'bot';
	return 'other';
}

function stp_brain_ua_family() {
	return stp_brain_ua_family_from_string( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
}

function stp_brain_features_for_event( $type, $args, $ip_row = null ) {
	$url = (string) ( $args['url'] ?? ( $_SERVER['REQUEST_URI'] ?? '/' ) );
	$hour_value = ! empty( $args['_brain_hour'] ) ? sanitize_text_field( $args['_brain_hour'] ) : current_time( 'D-H' );
	$ua_value = ! empty( $args['_brain_ua_family'] ) ? sanitize_key( $args['_brain_ua_family'] ) : stp_brain_ua_family();
	$country_value = ! empty( $args['_brain_country_code'] ) ? strtoupper( sanitize_text_field( $args['_brain_country_code'] ) ) : ( $ip_row && ! empty( $ip_row->country_code ) ? strtoupper( $ip_row->country_code ) : '' );
	$features = array(
		array( 'type' => 'event_type', 'value' => $type ),
		array( 'type' => 'path_bucket', 'value' => stp_brain_path_bucket( $url ) ),
		array( 'type' => 'hour', 'value' => $hour_value ),
		array( 'type' => 'ua_family', 'value' => $ua_value ),
	);
	if ( $country_value ) $features[] = array( 'type' => 'country', 'value' => $country_value );
	if ( ! empty( $args['sub'] ) ) $features[] = array( 'type' => 'event_sub', 'value' => $type . ':' . $args['sub'] );
	return $features;
}

function stp_brain_context( $type, $args, $ip_row = null ) {
	if ( empty( stp_cfg()['v2_site_brain'] ) || ! stp_table_exists( 'brain' ) ) return array();
	global $wpdb;
	$score = 0;
	$discount = 0;
	$reasons = array();
	foreach ( stp_brain_features_for_event( $type, $args, $ip_row ) as $feature ) {
		$key = stp_brain_feature_key( $feature['type'], $feature['value'] );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . stp_t( 'brain' ) . " WHERE feature_key=%s", $key ) );
		if ( ! $row ) { $score += 4; $reasons[] = 'Site Brain: new ' . $feature['type']; continue; }
		$total = max( 1, (int) $row->good_count + (int) $row->risk_count );
		$risk_rate = (int) $row->risk_count / $total;
		$confidence = min( 100, (int) $row->confidence );
		if ( $confidence >= 12 && $risk_rate >= 0.55 ) {
			$score += min( 22, 8 + (int) round( $risk_rate * 20 ) );
			$reasons[] = 'Site Brain: risky ' . $feature['type'] . ' pattern';
		} elseif ( $confidence >= 20 && $risk_rate <= 0.05 ) {
			$discount += 4;
		}
	}
	$out = array();
	if ( $score > 0 ) {
		$out['v2_brain_score'] = min( 45, $score );
		$out['v2_brain_reasons'] = array_slice( array_unique( $reasons ), 0, 4 );
	}
	if ( $discount > 0 ) $out['v2_brain_discount'] = min( 12, $discount );
	return $out;
}

function stp_brain_observe( $event_id, $type, $args, $risk, $ip_row = null, $force = false, $target_table = '' ) {
	if ( ( empty( stp_cfg()['v2_site_brain'] ) && ! $force ) || ! stp_table_exists( 'brain' ) ) return;
	global $wpdb;
	$table = $target_table ? stp_safe_table_name( $target_table ) : stp_t( 'brain' );
	if ( ! $table ) return;
	$sub = sanitize_key( $args['sub'] ?? '' );
	$is_trusted_source = $ip_row && ( $ip_row->status ?? '' ) === 'trusted';
	$is_containment = stp_is_containment_event( $type, $sub );
	$is_risk = ( (int) ( $risk['score'] ?? 0 ) >= 60 || ( $risk['flag'] ?? '' ) === 'red' || $is_containment );
	if ( $is_trusted_source && $is_containment && ! in_array( $sub, array( 'honeypot_hit', 'login_country_policy', 'attack_graph_preemptive_limit' ), true ) ) {
		$is_risk = false;
	}
	foreach ( stp_brain_features_for_event( $type, $args, $ip_row ) as $feature ) {
		$key = stp_brain_feature_key( $feature['type'], $feature['value'] );
		$meta = array( 'type' => $feature['type'], 'sample' => substr( (string) $feature['value'], 0, 160 ), 'last_event_id' => (int) $event_id );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (feature_key,feature_type,good_count,risk_count,last_score,confidence,meta,first_seen,last_seen)
			 VALUES (%s,%s,%d,%d,%d,1,%s,NOW(),NOW())
			 ON DUPLICATE KEY UPDATE good_count=good_count+%d,risk_count=risk_count+%d,last_score=%d,confidence=LEAST(100,good_count+risk_count+1),meta=%s,last_seen=NOW()",
			$key, sanitize_key( $feature['type'] ), $is_risk ? 0 : 1, $is_risk ? 1 : 0, (int) $risk['score'], wp_json_encode( $meta ),
			$is_risk ? 0 : 1, $is_risk ? 1 : 0, (int) $risk['score'], wp_json_encode( $meta )
		) );
	}
}

function stp_ai_hash_value( $value, $prefix = '' ) {
	$value = (string) $value;
	if ( $value === '' ) return '';
	$scope = function_exists( 'current_time' ) ? current_time( 'Y-m' ) : gmdate( 'Y-m' );
	return $prefix . substr( hash_hmac( 'sha256', $scope . '|' . $value, wp_salt( 'secure_auth' ) . '|stp-ai-monthly' ), 0, 20 );
}

function stp_containment_event_subs() {
	return array( 'ip_block', 'subnet_ban', 'country_blocklist', 'login_country_policy', 'endpoint_rate_limit', 'author_archive_blocked', 'user_enumeration_blocked', 'honeypot_hit', 'attack_graph_preemptive_limit' );
}

function stp_is_containment_event( $type, $sub = '' ) {
	$sub = sanitize_key( $sub );
	return in_array( $type, array( 'protection_block', 'waf_block', 'rest_abuse' ), true ) || in_array( $sub, stp_containment_event_subs(), true );
}

function stp_ai_event_lane( $type, $sub = '' ) {
	$sub = sanitize_key( $sub );
	if ( $type === 'protection_block' ) return 'protected_containment';
	if ( $type === 'waf_block' || in_array( $sub, array( 'honeypot_hit', 'attack_graph_preemptive_limit' ), true ) ) return 'active_attack_blocked';
	if ( $type === 'rest_abuse' || in_array( $sub, array( 'author_archive_blocked', 'user_enumeration_blocked' ), true ) ) return 'recon_blocked';
	if ( $type === 'login_success' ) return 'auth_success';
	if ( $type === 'login_failed' ) return 'auth_failure';
	if ( in_array( $type, array( 'admin_activity', 'post_action', 'product_action', 'order_action', 'user_action', 'setting_change', 'file_edit', 'plugin_action' ), true ) ) return 'logged_in_activity';
	return 'traffic_observation';
}

function stp_ai_upgrade_packet_from_event( $packet, $event_id ) {
	global $wpdb;
	$event_id = (int) $event_id;
	if ( ! $event_id ) return is_array( $packet ) ? $packet : array();
	$event = $wpdb->get_row( $wpdb->prepare(
		"SELECT e.*,i.ip_address,i.status AS ip_status FROM " . stp_t( 'events' ) . " e LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id WHERE e.id=%d",
		$event_id
	) );
	if ( ! $event ) return is_array( $packet ) ? $packet : array();
	$packet = is_array( $packet ) ? $packet : array();
	$extra = json_decode( (string) $event->extra, true );
	$extra = is_array( $extra ) ? $extra : array();
	$sub = sanitize_key( $event->event_sub ?? '' );
	$ip = (string) ( $event->ip_address ?? '' );
	$decision = $ip ? stp_block_decision( $ip ) : array( 'blocked' => false, 'source' => '' );
	$contained = stp_is_containment_event( $event->event_type, $sub );
	$is_trusted = ( $event->ip_status ?? '' ) === 'trusted';
	$packet['v'] = 2;
	$packet['task'] = 'classify_security_decision';
	$packet['e'] = (string) $event->event_type;
	$packet['sub'] = $sub;
	$packet['lane'] = stp_ai_event_lane( $event->event_type, $sub );
	$packet['contained'] = $contained ? 1 : 0;
	$packet['access'] = ! empty( $decision['blocked'] ) ? sanitize_key( $decision['source'] ) : ( $is_trusted ? 'trusted' : 'open' );
	$packet['trusted'] = $is_trusted ? 1 : 0;
	$packet['ip'] = stp_ai_hash_value( $ip, 'ip_' );
	$packet['ipfam'] = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 'v6' : 'v4';
	$packet['net'] = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? stp_ai_hash_value( stp_subnet24_cidr( $ip ), 'net_' ) : '';
	$packet['p'] = stp_brain_path_bucket( $event->url ?? '' );
	$packet['s'] = (int) $event->risk_score;
	$packet['r'] = substr( (string) $event->risk_reasons, 0, 180 );
	$compact_extra = array_intersect_key( $extra, array_flip( array( 'block_source', 'endpoint', 'policy', 'response_code', 'payload_score', 'payload_flags', 'would_waf_block' ) ) );
	if ( $sub ) $compact_extra['sub'] = $sub;
	$packet['x'] = substr( wp_strip_all_tags( wp_json_encode( $compact_extra ) ), 0, 160 );
	$packet['guide'] = $contained ? 'already_denied_or_contained; use protected unless bypass/compromise evidence' : 'classify observed risk';
	return $packet;
}

function stp_ai_queue_maybe( $event_id, $type, $args, $risk, $ip_str ) {
	$cfg = stp_cfg();
	if ( empty( $cfg['v2_site_brain'] ) || ! stp_table_exists( 'ai_queue' ) ) return 0;
	$score = (int) ( $risk['score'] ?? 0 );
	if ( $score < (int) $cfg['v2_uncertain_low'] || $score > (int) $cfg['v2_uncertain_high'] ) return 0;
	global $wpdb;
	$broker_status = stp_ai_shared_broker_status();
	$provider = sanitize_key( $broker_status['provider'] ?? 'none' );
	$status = ! empty( $broker_status['ready'] ) ? 'pending' : 'local_only';
	$event_extra = is_array( $args['extra'] ?? null ) ? (array) $args['extra'] : array();
	$sub = sanitize_key( $args['sub'] ?? '' );
	$contained = stp_is_containment_event( $type, $sub );
	if ( $contained && $score < 60 && empty( $event_extra['would_waf_block'] ) && ! in_array( $sub, array( 'login_country_policy', 'honeypot_hit', 'attack_graph_preemptive_limit' ), true ) ) {
		return 0;
	}
	$decision = stp_block_decision( $ip_str );
	$ip_row = stp_get_ip_row( $ip_str );
	$is_trusted = ( $ip_row && ( $ip_row->status ?? '' ) === 'trusted' ) || stp_ip_status_is_trusted( $ip_str );
	$extra = array_intersect_key( array_merge( (array) $args, $event_extra ), array_flip( array( 'sub', 'obj_type', 'username', 'block_source', 'endpoint', 'policy', 'response_code', 'payload_score', 'payload_flags', 'would_waf_block' ) ) );
	if ( ! empty( $extra['username'] ) ) $extra['username'] = stp_ai_hash_value( $extra['username'], 'user_' );
	$context = array(
		'v' => 2,
		'task' => 'classify_security_decision',
		'e' => $type,
		'sub' => $sub,
		'lane' => stp_ai_event_lane( $type, $sub ),
		'contained' => $contained ? 1 : 0,
		'access' => ! empty( $decision['blocked'] ) ? sanitize_key( $decision['source'] ) : ( $is_trusted ? 'trusted' : 'open' ),
		'trusted' => $is_trusted ? 1 : 0,
		'ip' => stp_ai_hash_value( $ip_str, 'ip_' ),
		'ipfam' => filter_var( $ip_str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 'v6' : 'v4',
		'net' => filter_var( $ip_str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? stp_ai_hash_value( stp_subnet24_cidr( $ip_str ), 'net_' ) : '',
		'p' => stp_brain_path_bucket( $args['url'] ?? '' ),
		's' => $score,
		'r' => substr( (string) ( $risk['reasons'] ?? '' ), 0, 180 ),
		'x' => substr( wp_strip_all_tags( wp_json_encode( $extra ) ), 0, 160 ),
		'guide' => $contained ? 'already_denied_or_contained; use protected unless bypass/compromise evidence' : 'classify observed risk',
	);
	$ok = $wpdb->insert( stp_t( 'ai_queue' ), array(
		'event_id' => (int) $event_id,
		'provider' => $provider,
		'local_score' => $score,
		'status' => $status,
		'compact_context' => wp_json_encode( $context ),
		'created_at' => current_time( 'mysql' ),
	) );
	$qid = $ok ? (int) $wpdb->insert_id : 0;
	if ( $qid && $status === 'pending' && ( $cfg['v2_ai_mode'] ?? 'batch' ) === 'always' ) {
		stp_ai_process_queue_item( $qid );
	}
	return $qid;
}

function stp_ai_status( $status = null ) {
	if ( $status === null ) return (array) get_option( 'stp_ai_status', array() );
	$status = array_merge( (array) get_option( 'stp_ai_status', array() ), (array) $status, array( 'updated_at' => current_time( 'mysql' ) ) );
	update_option( 'stp_ai_status', $status, false );
	return $status;
}

function stp_ai_parse_json_text( $text ) {
	$text = trim( (string) $text );
	$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
	$first = json_decode( $text, true );
	if ( is_string( $first ) ) {
		$second = json_decode( $first, true );
		if ( is_array( $second ) ) return $second;
	}
	if ( is_array( $first ) ) return $first;
	if ( preg_match( '/\{.*\}/s', $text, $m ) ) $text = $m[0];
	$data = json_decode( $text, true );
	if ( is_array( $data ) ) return $data;
	return null;
}

function stp_ai_is_quota_error( $thing ) {
	$msg = is_wp_error( $thing ) ? $thing->get_error_message() : (string) $thing;
	return (bool) preg_match( '/quota|rate.?limit|resource_exhausted|too many requests|retry in/i', $msg );
}

function stp_ai_extract_texts( $node, &$out = array() ) {
	if ( is_array( $node ) ) {
		foreach ( $node as $k => $v ) {
			if ( $k === 'text' && is_scalar( $v ) ) $out[] = (string) $v;
			else stp_ai_extract_texts( $v, $out );
		}
	}
	return $out;
}

function stp_ai_models_status( $status = null ) {
	if ( $status === null ) return (array) get_option( 'stp_ai_models_status', array() );
	$status = array_merge( (array) get_option( 'stp_ai_models_status', array() ), (array) $status, array( 'updated_at' => current_time( 'mysql' ) ) );
	update_option( 'stp_ai_models_status', $status, false );
	return $status;
}

function stp_ai_models_option_key( $provider = '' ) {
	$provider = sanitize_key( $provider ?: ( stp_cfg()['v2_ai_provider'] ?? 'gemini' ) );
	return 'stp_ai_models_' . ( $provider ?: 'gemini' );
}

function stp_ai_model_tier_label( $provider, $model ) {
	$provider = sanitize_key( $provider );
	$model = strtolower( (string) $model );
	if ( $provider === 'gemini' ) {
		if ( preg_match( '/flash-lite|flash/i', $model ) && ! preg_match( '/image|tts|preview/i', $model ) ) return 'Free-friendly';
		if ( preg_match( '/pro|image|tts|preview/i', $model ) ) return 'Paid/limited';
		return 'Check quota';
	}
	if ( $provider === 'groq' ) return 'Free Plan';
	if ( $provider === 'xai' ) return 'Paid/credits';
	return 'Unknown tier';
}

function stp_ai_default_models( $provider ) {
	$provider = sanitize_key( $provider );
	if ( $provider === 'gemini' ) {
		return array(
			array( 'name' => 'gemini-2.5-flash-lite', 'label' => 'Gemini 2.5 Flash-Lite', 'tier' => 'Free-friendly' ),
			array( 'name' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash', 'tier' => 'Free-friendly' ),
			array( 'name' => 'gemini-2.0-flash-lite', 'label' => 'Gemini 2.0 Flash-Lite', 'tier' => 'Free-friendly' ),
		);
	}
	if ( $provider === 'groq' ) {
		return array(
			array( 'name' => 'llama-3.3-70b-versatile', 'label' => 'Llama 3.3 70B Versatile', 'tier' => 'Free Plan' ),
			array( 'name' => 'llama-3.1-8b-instant', 'label' => 'Llama 3.1 8B Instant', 'tier' => 'Free Plan' ),
			array( 'name' => 'qwen/qwen3-32b', 'label' => 'Qwen 3 32B', 'tier' => 'Free Plan' ),
		);
	}
	if ( $provider === 'xai' ) {
		return array(
			array( 'name' => 'grok-4.3', 'label' => 'Grok 4.3', 'tier' => 'Paid/credits' ),
			array( 'name' => 'grok-4', 'label' => 'Grok 4', 'tier' => 'Paid/credits' ),
			array( 'name' => 'grok-build-0.1', 'label' => 'Grok Build 0.1', 'tier' => 'Paid/credits' ),
		);
	}
	return array();
}

function stp_ai_openai_provider_meta( $provider ) {
	$provider = sanitize_key( $provider );
	if ( $provider === 'groq' ) {
		return array(
			'label' => 'Groq',
			'models_url' => 'https://api.groq.com/openai/v1/models',
			'chat_url' => 'https://api.groq.com/openai/v1/chat/completions',
			'default_model' => 'llama-3.1-8b-instant',
		);
	}
	if ( $provider === 'xai' ) {
		return array(
			'label' => 'xAI Grok',
			'models_url' => 'https://api.x.ai/v1/models',
			'chat_url' => 'https://api.x.ai/v1/chat/completions',
			'default_model' => 'grok-4.3',
		);
	}
	return array();
}

function stp_ai_sanitize_review_result( $parsed, $provider, $model ) {
	if ( ! is_array( $parsed ) ) return new WP_Error( 'stp_ai_bad_json', 'AI provider returned unreadable JSON.' );
	$label = sanitize_key( $parsed['label'] ?? 'suspicious' );
	if ( $label === 'benign' ) $label = 'clean';
	if ( $label === 'watch' ) $label = 'suspicious';
	if ( $label === 'escalate' ) $label = 'critical';
	if ( ! in_array( $label, array( 'clean', 'protected', 'suspicious', 'critical' ), true ) ) {
		return new WP_Error( 'stp_ai_bad_label', 'AI provider returned an unexpected label.' );
	}
	return array(
		'score' => max( 0, min( 100, (int) ( $parsed['score'] ?? 0 ) ) ),
		'label' => $label,
		'reason' => substr( sanitize_text_field( $parsed['reason'] ?? '' ), 0, 120 ),
		'model' => (string) $model,
	);
}

function stp_ai_apply_review_result( $queue_row, $review ) {
	global $wpdb;
	if ( empty( $queue_row->event_id ) || ! is_array( $review ) ) return;
	$score = (int) ( $review['score'] ?? 0 );
	$label = sanitize_key( $review['label'] ?? '' );
	if ( $label !== 'critical' && $score < 85 ) return;
	$event = $wpdb->get_row( $wpdb->prepare(
		"SELECT e.*,i.ip_address,i.country_code FROM " . stp_t( 'events' ) . " e LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id WHERE e.id=%d",
		(int) $queue_row->event_id
	) );
	if ( ! $event || empty( $event->ip_address ) ) return;
	stp_create_alert( array(
		'chain_type'  => 'ai_critical_review',
		'severity'    => 'critical',
		'ip_address'  => $event->ip_address,
		'subnet_24'   => stp_subnet24_cidr( $event->ip_address ),
		'user_id'     => (int) $event->user_id,
		'username'    => $event->username,
		'event_id'    => (int) $event->id,
		'title'       => 'AI escalated uncertain event',
		'description' => 'AI reviewed an uncertain ' . $event->event_type . ' event and returned ' . strtoupper( $label ) . ' with score ' . $score . '/100. Reason: ' . ( $review['reason'] ?? '' ),
		'evidence'    => array(
			'provider' => $queue_row->provider,
			'model' => $review['model'] ?? '',
			'local_score' => (int) $queue_row->local_score,
			'ai_score' => $score,
			'ai_label' => $label,
			'ai_reason' => $review['reason'] ?? '',
			'event_type' => $event->event_type,
			'event_sub' => $event->event_sub,
			'url' => $event->url,
		),
	) );
}

function stp_ai_review_packet( $packet, $cfg ) {
	if ( ! class_exists( '\\DSA\\AI\\AI_Broker_Service' ) || ! class_exists( '\\DSA\\Settings' ) ) {
		return new WP_Error( 'stp_ai_broker_unavailable', 'Kiwe AI broker is unavailable.' );
	}

	$broker = new \DSA\AI\AI_Broker_Service( new \DSA\Settings() );
	$result = $broker->request(
		array(
			'service'    => 'securetrack',
			'capability' => 'classify_security',
			'operation'  => 'site_brain_review',
			'system'     => 'Return strict JSON only: {"score":0-100,"label":"clean|protected|suspicious|critical","reason":"max 8 words"}. clean=benign; protected=already contained; suspicious=watch; critical=missed attack or compromise. Never call an already-contained protection block suspicious without bypass evidence.',
			'user'       => 'SECURETRACK_PACKET=' . wp_json_encode( $packet ),
		)
	);
	if ( empty( $result['called'] ) || empty( $result['ok'] ) ) {
		$message = (string) ( $result['error']['message'] ?? $result['reason'] ?? 'Kiwe AI broker did not complete the review.' );
		return new WP_Error( sanitize_key( (string) ( $result['error']['code'] ?? 'stp_ai_broker' ) ), $message );
	}

	$parsed = $result['validation']['data'] ?? stp_ai_parse_json_text( (string) ( $result['output'] ?? '' ) );
	return stp_ai_sanitize_review_result( $parsed, (string) ( $result['provider'] ?? 'kiwe_broker' ), (string) ( $result['model'] ?? '' ) );
}

function stp_ai_shared_broker_status() {
	$out = array( 'ready' => false, 'provider' => 'none', 'model' => '' );
	if ( ! class_exists( '\\DSA\\AI\\AI_Broker_Service' ) || ! class_exists( '\\DSA\\Settings' ) ) return $out;
	$status = ( new \DSA\AI\AI_Broker_Service( new \DSA\Settings() ) )->status( 'securetrack' );
	$provider = is_array( $status['provider'] ?? null ) ? $status['provider'] : array();
	$out['provider'] = sanitize_key( (string) ( $provider['provider'] ?? 'none' ) );
	$out['model'] = sanitize_text_field( (string) ( $provider['model'] ?? '' ) );
	$out['ready'] = ! empty( $status['profile']['enabled'] ) && ! empty( $provider['nativeGeneration'] ) && ! empty( $provider['configured'] );
	return $out;
}

function stp_ai_process_queue_item( $queue_id ) {
	global $wpdb;
	$cfg = stp_cfg();
	$broker_status = stp_ai_shared_broker_status();
	if ( empty( $cfg['v2_site_brain'] ) || empty( $broker_status['ready'] ) || ! stp_table_exists( 'ai_queue' ) ) {
		return new WP_Error( 'stp_ai_not_ready', 'Shared Kiwe AI broker is not configured for SecureTrack.' );
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . stp_t( 'ai_queue' ) . " WHERE id=%d AND status='pending'", (int) $queue_id ) );
	if ( ! $row ) return new WP_Error( 'stp_ai_queue_missing', 'Pending AI queue item was not found.' );
	$packet = json_decode( (string) $row->compact_context, true );
	$packet = is_array( $packet ) ? $packet : array( 'e' => 'unknown', 's' => (int) $row->local_score );
	if ( (int) ( $packet['v'] ?? 1 ) < 2 ) {
		$packet = stp_ai_upgrade_packet_from_event( $packet, (int) $row->event_id );
		$wpdb->update( stp_t( 'ai_queue' ), array( 'compact_context' => wp_json_encode( $packet ) ), array( 'id' => $row->id ) );
	}
	$res = stp_ai_review_packet( $packet, $cfg );
	if ( is_wp_error( $res ) ) {
		if ( stp_ai_is_quota_error( $res ) ) {
			$wpdb->update( stp_t( 'ai_queue' ), array( 'ai_reason' => substr( 'Paused: ' . $res->get_error_message(), 0, 500 ) ), array( 'id' => $row->id ) );
		} else {
			$wpdb->update( stp_t( 'ai_queue' ), array( 'status' => 'error', 'ai_reason' => substr( $res->get_error_message(), 0, 500 ), 'reviewed_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
		}
		stp_ai_status( array( 'connected' => 0, 'provider' => $broker_status['provider'], 'model' => $broker_status['model'], 'message' => $res->get_error_message() ) );
		return $res;
	}
	$wpdb->update( stp_t( 'ai_queue' ), array( 'status' => 'reviewed', 'ai_score' => (int) $res['score'], 'ai_label' => $res['label'], 'ai_reason' => $res['reason'], 'reviewed_at' => current_time( 'mysql' ) ), array( 'id' => $row->id ) );
	stp_ai_apply_review_result( $row, $res );
	stp_ai_status( array( 'connected' => 1, 'provider' => $broker_status['provider'], 'model' => $res['model'] ?? $broker_status['model'], 'message' => 'Last isolated broker review succeeded.', 'last_score' => (int) $res['score'], 'last_label' => $res['label'] ) );
	return $res;
}

function stp_ai_process_pending_queue( $limit = 10 ) {
	global $wpdb;
	$limit = max( 1, min( 50, (int) $limit ) );
	if ( ! stp_table_exists( 'ai_queue' ) ) return array( 'reviewed' => 0, 'errors' => 0 );
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . stp_t( 'ai_queue' ) . " WHERE status='pending' ORDER BY id ASC LIMIT %d", $limit ) );
	$out = array( 'reviewed' => 0, 'errors' => 0 );
	foreach ( $ids as $id ) {
		$res = stp_ai_process_queue_item( (int) $id );
		if ( is_wp_error( $res ) ) {
			$out['errors']++;
			if ( stp_ai_is_quota_error( $res ) ) {
				$out['paused'] = 1;
				break;
			}
		}
		else $out['reviewed']++;
	}
	return $out;
}

function stp_ai_test_connection() {
	$cfg = stp_cfg( true );
	$broker_status = stp_ai_shared_broker_status();
	$provider = $broker_status['provider'];
	if ( empty( $broker_status['ready'] ) ) {
		stp_ai_status( array( 'connected' => 0, 'provider' => $provider, 'message' => 'Shared Kiwe AI broker is not ready for SecureTrack.' ) );
		return new WP_Error( 'stp_ai_none', 'Shared Kiwe AI broker is not ready for SecureTrack.' );
	}
	$packet = array( 'v' => 1, 'e' => 'connection_test', 'ip' => '0.0.0.0', 'p' => '/', 's' => 35, 'r' => 'test', 'x' => 'stp' );
	$res = stp_ai_review_packet( $packet, $cfg );
	if ( is_wp_error( $res ) ) {
		stp_ai_status( array( 'connected' => 0, 'provider' => $provider, 'model' => $broker_status['model'], 'message' => $res->get_error_message() ) );
		return $res;
	}
	stp_ai_status( array( 'connected' => 1, 'provider' => $provider, 'model' => $res['model'] ?? $broker_status['model'], 'message' => 'Shared broker connected and returned validated JSON.', 'last_score' => (int) $res['score'], 'last_label' => $res['label'] ) );
	return $res;
}

function stp_run_ai_queue() {
	stp_ai_process_pending_queue( 10 );
}

function stp_brain_train_from_history( $limit = 20000 ) {
	global $wpdb;
	if ( ! stp_table_exists( 'events' ) || ! stp_table_exists( 'brain' ) ) return array( 'processed' => 0, 'features' => 0 );
	$limit = max( 100, min( 100000, (int) $limit ) );
	$stage = stp_t( 'brain_stage_' . substr( md5( microtime( true ) . wp_rand() ), 0, 10 ) );
	$old_table = stp_t( 'brain_old_' . substr( md5( microtime( true ) . wp_rand() ), 0, 10 ) );
	$stage = stp_safe_table_name( $stage );
	$old_table = stp_safe_table_name( $old_table );
	$active = stp_t( 'brain' );
	if ( ! $stage || ! $old_table ) return array( 'processed' => 0, 'features' => 0, 'error' => 'invalid staging table' );
	$wpdb->query( "DROP TABLE IF EXISTS {$stage}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$old_table}" );
	$created = $wpdb->query( "CREATE TABLE {$stage} LIKE {$active}" );
	if ( $created === false ) return array( 'processed' => 0, 'features' => 0, 'error' => $wpdb->last_error );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT e.id,e.session_id,e.event_type,e.event_sub,e.url,e.risk_score,e.flag_status,e.created_at,
		        i.country_code,s.user_agent
		 FROM " . stp_t( 'events' ) . " e
		 LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 LEFT JOIN " . stp_t( 'sessions' ) . " s ON s.id=e.session_id
		 ORDER BY e.id DESC LIMIT %d",
		$limit
	) );
	$rows = array_reverse( (array) $rows );
	$processed = 0;
	try {
		foreach ( $rows as $row ) {
			$args = array(
				'url' => $row->url,
				'sub' => $row->event_sub,
				'_brain_hour' => $row->created_at ? date( 'D-H', strtotime( $row->created_at ) ) : current_time( 'D-H' ),
				'_brain_ua_family' => stp_brain_ua_family_from_string( $row->user_agent ?? '' ),
				'_brain_country_code' => $row->country_code,
			);
			$risk = array(
				'score' => (int) $row->risk_score,
				'flag' => (string) $row->flag_status,
				'reasons' => '',
			);
			stp_brain_observe( (int) $row->id, $row->event_type, $args, $risk, (object) array( 'country_code' => $row->country_code ), true, $stage );
			$processed++;
		}
		$features = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$stage}" );
		$swap_ok = $wpdb->query( "RENAME TABLE {$active} TO {$old_table}, {$stage} TO {$active}" );
		if ( $swap_ok === false ) {
			$error = $wpdb->last_error ?: 'database rename swap failed';
			$wpdb->query( "DROP TABLE IF EXISTS {$stage}" );
			return array( 'processed' => $processed, 'features' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$active}" ), 'error' => $error );
		}
	} catch ( Exception $e ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$stage}" );
		return array( 'processed' => $processed, 'features' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$active}" ), 'error' => $e->getMessage() );
	}
	$wpdb->query( "DROP TABLE IF EXISTS {$old_table}" );
	update_option( 'stp_brain_last_training', array(
		'time' => current_time( 'mysql' ),
		'processed' => $processed,
		'features' => $features,
		'limit' => $limit,
	), false );
	return array( 'processed' => $processed, 'features' => $features );
}


// ════════════════════════════════════════════════════════════════

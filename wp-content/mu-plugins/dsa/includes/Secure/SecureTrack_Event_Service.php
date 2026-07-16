<?php

namespace DSA\Secure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecureTrack_Event_Service {
	public static function log( $type, $args = [] ) {
		global $wpdb;

		$args = is_array( $args ) ? $args : [];
		if ( ! stp_tables_ready() && ! stp_repair_database() ) {
			return null;
		}

		$config = stp_cfg();
		stp_diag( 'last_log_attempt', [ 'type' => $type, 'url' => $args['url'] ?? '', 'time' => current_time( 'mysql' ), 'user_id' => get_current_user_id() ] );

		if ( $type === 'page_view' && $config['exclude_admin'] && current_user_can( 'manage_options' ) ) {
			stp_diag( 'last_skip_reason', 'Admin page view skipped because Exclude logged-in admins is enabled.' );
			return null;
		}

		$ip_str = stp_get_ip();
		$ip = stp_upsert_ip( $ip_str );
		if ( empty( $ip->id ) ) {
			stp_diag( 'last_db_error', 'IP insert/upsert failed: ' . $wpdb->last_error );
			return null;
		}

		$session = null;
		if ( $config['track_visitors'] && stp_should_create_session_for_event( $type ) ) {
			$session = stp_upsert_session( $ip );
			if ( empty( $session->id ) ) {
				stp_repair_database();
				$session = stp_upsert_session( $ip );
				if ( empty( $session->id ) ) {
					stp_diag( 'last_db_error', 'Session insert/upsert failed after migration: ' . $wpdb->last_error );
					$session = null;
				}
			}

			$bot_security_types = [ 'waf_block', 'protection_block', 'break_glass_access', 'login_failed', 'login_success', 'xmlrpc', 'rest_abuse', 'pk_auth_fail' ];
			if ( $session && $session->is_bot && ! in_array( $type, $bot_security_types, true ) ) {
				stp_diag( 'last_skip_reason', 'Known bot session skipped.' );
				return null;
			}
		}

		$context = array_merge(
			$args,
			[
				'ip_blocked'      => ( $ip->status === 'blocked' ),
				'ip_trusted'      => ( $ip->status === 'trusted' ),
				'ip_proxy'        => (bool) $ip->is_proxy,
				'ip_high_risk'    => (int) $ip->risk_score >= 70,
				'nearby_threat'   => ( $ip->status === 'trusted' ) ? false : stp_ip_has_nearby_threat( $ip_str ),
				'adaptive_repeat' => ! empty( stp_cfg()['adaptive_learning'] ) ? stp_ip_recent_similar_reason( $ip_str, $args['url'] ?? '', $type ) : false,
			]
		);

		$uid_for_context = (int) ( $args['user_id'] ?? get_current_user_id() );
		if ( $uid_for_context && stp_user_profile_knows_ip( $uid_for_context, $ip_str ) ) {
			$context['profile_trusted_ip'] = true;
		}

		$behavior = stp_behavioral_anomaly( $uid_for_context, $ip_str, $type, $args );
		if ( ! empty( $behavior['score'] ) ) {
			$context['behavior_anomaly_score'] = (int) $behavior['score'];
			$context['behavior_anomaly_reasons'] = $behavior['reasons'];
		}

		$brain_context = stp_brain_context( $type, $args, $ip );
		if ( $brain_context ) {
			$context = array_merge( $context, $brain_context );
		}
		if ( $type === 'login_success' && stp_ip_recent_attack_chain( $ip_str ) ) {
			$context['attack_chain'] = true;
		}
		if ( $uid_for_context && ! ( $context['ip_trusted'] ?? false ) && ! ( $context['profile_trusted_ip'] ?? false ) && stp_user_recent_different_untrusted_ip( $uid_for_context, $ip_str ) ) {
			$context['same_user_new_ip'] = true;
		}

		$risk = stp_risk( $type, $context );
		$uid = $uid_for_context;
		$username = $args['username'] ?? '';
		if ( ! $username && $uid ) {
			$user = get_userdata( $uid );
			$username = $user ? $user->user_login : '';
		}

		$event_data = [
			'session_id'   => $session ? $session->id : null,
			'ip_id'        => $ip->id,
			'user_id'      => $uid,
			'username'     => substr( $username, 0, 200 ),
			'event_type'   => $type,
			'event_sub'    => $args['sub'] ?? null,
			'obj_type'     => $args['obj_type'] ?? null,
			'obj_id'       => $args['obj_id'] ?? null,
			'obj_title'    => isset( $args['obj_title'] ) ? substr( $args['obj_title'], 0, 500 ) : null,
			'url'          => isset( $args['url'] ) ? substr( $args['url'], 0, 1000 ) : null,
			'extra'        => isset( $args['extra'] ) ? wp_json_encode( $args['extra'] ) : null,
			'risk_score'   => $risk['score'],
			'risk_reasons' => substr( $risk['reasons'], 0, 500 ),
			'flag_status'  => $risk['flag'],
			'created_at'   => current_time( 'mysql' ),
		];

		$event_data['hash_prev'] = $wpdb->get_var( 'SELECT hash_current FROM ' . stp_t( 'events' ) . " WHERE hash_current IS NOT NULL AND hash_current<>'' ORDER BY id DESC LIMIT 1" ) ?: null;
		$event_data['hash_current'] = stp_event_hash( $event_data );
		$ok = $wpdb->insert( stp_t( 'events' ), $event_data );

		if ( ! $ok ) {
			stp_diag( 'last_db_error', 'Event insert failed: ' . $wpdb->last_error );
			stp_diag( 'last_failed_event', [ 'type' => $type, 'ip' => $ip_str, 'url' => $args['url'] ?? '' ] );
			return null;
		}

		$event_id = (int) $wpdb->insert_id;
		stp_diag( 'last_insert', [ 'id' => $event_id, 'type' => $type, 'ip' => $ip_str, 'time' => current_time( 'mysql' ) ] );
		stp_diag( 'last_skip_reason', '' );

		$is_attack_event = ( $risk['flag'] === 'red' || (int) $risk['score'] >= 60 || stripos( $risk['reasons'], 'Attack path probed' ) !== false );
		stp_brain_observe( $event_id, $type, $args, $risk, $ip );
		stp_ai_queue_maybe( $event_id, $type, $args, $risk, $ip_str );
		stp_update_subnet_intel( $ip_str, $is_attack_event );

		$graph = stp_attack_graph_update( $ip_str, $type, $args, $risk );
		if ( $graph ) {
			stp_create_alert(
				[
					'chain_type'  => $graph['type'],
					'severity'    => $graph['severity'],
					'ip_address'  => $ip_str,
					'subnet_24'   => stp_subnet24_cidr( $ip_str ),
					'user_id'     => $uid,
					'username'    => $username,
					'event_id'    => $event_id,
					'title'       => 'Attack graph prediction',
					'description' => $graph['detail'],
					'evidence'    => $graph['evidence'],
				]
			);
		}

		$chain = stp_detect_chain( $ip_str, $uid, $type, $risk, $args );
		if ( $chain ) {
			stp_create_alert(
				[
					'chain_type'  => $chain['type'],
					'severity'    => $chain['severity'],
					'ip_address'  => $ip_str,
					'subnet_24'   => stp_subnet24_cidr( $ip_str ),
					'user_id'     => $uid,
					'username'    => $username,
					'event_id'    => $event_id,
					'title'       => stp_chain_title( $chain['type'] ),
					'description' => $chain['detail'],
					'evidence'    => [ 'event_type' => $type, 'score' => $risk['score'], 'reasons' => $risk['reasons'], 'url' => $args['url'] ?? '' ],
				]
			);
		} elseif ( $risk['flag'] === 'red' && (int) $risk['score'] >= 75 && empty( $args['suppress_high_risk_alert'] ) && ! in_array( $type, [ 'break_glass_access' ], true ) ) {
			$alert_severity = ( (int) $risk['score'] >= 90 || in_array( $type, [ 'waf_block', 'login_failed', 'behavior_signal', 'break_glass_access' ], true ) ) ? 'critical' : 'high';
			stp_create_alert(
				[
					'chain_type'  => 'high_risk_event',
					'severity'    => $alert_severity,
					'ip_address'  => $ip_str,
					'subnet_24'   => stp_subnet24_cidr( $ip_str ),
					'user_id'     => $uid,
					'username'    => $username,
					'event_id'    => $event_id,
					'title'       => 'High-risk event: ' . $type,
					'description' => "Score {$risk['score']}/100. Reasons: {$risk['reasons']}",
					'evidence'    => [ 'event_type' => $type, 'url' => $args['url'] ?? '' ],
				]
			);
		}

		$new_ip_risk = min( 100, (int) round( ( (int) $ip->risk_score * 0.9 ) + ( $risk['score'] * 0.1 ) ) );
		$wpdb->update( stp_t( 'ips' ), [ 'risk_score' => $new_ip_risk ], [ 'id' => $ip->id ] );

		if ( $session && $risk['flag'] === 'red' ) {
			$wpdb->update( stp_t( 'sessions' ), [ 'flag_status' => 'red' ], [ 'id' => $session->id ] );
		}

		if ( $type === 'login_failed' && $config['block_brute_force'] && ! stp_enforcement_paused() ) {
			$recent = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . stp_t( 'events' ) . ' e
					 JOIN ' . stp_t( 'ips' ) . ' i ON i.id=e.ip_id
					 WHERE i.ip_address=%s AND e.event_type=%s
					   AND e.created_at > %s',
					$ip_str,
					'login_failed',
					date( 'Y-m-d H:i:s', strtotime( '-1 hour' ) )
				)
			);

			if ( $recent >= $config['brute_force_limit'] ) {
				if ( $ip->status === 'trusted' ) {
					stp_alert( "Trusted IP Failed Login Spike: {$ip_str}", "Trusted IP exceeded the brute-force threshold with {$recent} failed logins within 1 hour. It was not auto-blocked because it is trusted." );
				} else {
					$wpdb->update( stp_t( 'ips' ), [ 'status' => 'blocked', 'blocked_at' => current_time( 'mysql' ) ], [ 'id' => $ip->id ] );
					stp_alert( "IP Auto-Blocked: {$ip_str}", "Blocked after {$recent} failed logins within 1 hour." );
				}
			}
		}

		if ( $risk['flag'] === 'red' && $config['alert_on_red'] ) {
			stp_alert( "Red-Flag Event: {$type}", "IP: {$ip_str}\nUser: {$username}\nScore: {$risk['score']}/100\nReasons: {$risk['reasons']}" );
		}

		if ( $risk['flag'] === 'red' ) {
			do_action(
				'stp_webhook_fire',
				"Red-Flag: {$type}",
				[
					'event_type'   => $type,
					'risk_score'   => $risk['score'],
					'risk_reasons' => $risk['reasons'],
					'ip'           => $ip_str,
					'username'     => $username,
					'user_id'      => $uid,
					'url'          => $args['url'] ?? '',
				]
			);
		}

		return $event_id;
	}
}

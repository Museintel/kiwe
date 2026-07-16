<?php

if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

require_once __DIR__ . '/SecureTrack_Admin_Service.php';

//  EXTENDED ADMIN MENU  (adds to existing STP menu)
// ════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
	\DSA\Secure\SecureTrack_Admin_Service::register_extended_menus();
}, 11 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	\DSA\Secure\SecureTrack_Admin_Service::enqueue_extended_assets( $hook );
}, 11 );

// CSV download (not AJAX — streams file directly)
add_action( 'admin_post_stp_export_csv', 'stp_export_csv' );

function stp_csv_cell( $value ) {
	$value = is_scalar( $value ) ? (string) $value : '';
	$value = str_replace( array( "\r", "\n" ), ' ', $value );

	return preg_match( '/^[=+\-@\t]/', $value ) ? "'" . $value : $value;
}

function stp_csv_row( array $row ) {
	return array_map( 'stp_csv_cell', $row );
}


// ════════════════════════════════════════════════════════════════
//  PHONEKEY INTEGRATION BRIDGE
//  Wires PhoneKey's REST auth events into the SecureTrack event log.
//  Self-activates only when PhoneKey (PK_VER constant) is present.
// ════════════════════════════════════════════════════════════════

function stp_pk_active() {
	return defined( 'PK_VER' ) && function_exists( 'pk_activity_table' );
}

// ── Intercept PhoneKey REST responses ──────────────────────────
add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	if ( ! stp_pk_active() ) return $response;

	$route = $request->get_route();
	if ( strpos( $route, '/phonekey/v1/' ) === false ) return $response;

	$data   = $response->get_data();
	$path   = trim( str_replace( '/phonekey/v1/', '', $route ), '/' );
	$anchor = sanitize_text_field( $request->get_param( 'anchor' ) ?? '' );
	$type   = sanitize_key( $request->get_param( 'anchor_type' ) ?? '' );

	switch ( $path ) {

		case 'authenticate':
			if ( ! empty( $data['ok'] ) ) {
				stp_log( 'pk_auth', array(
					'sub'      => 'success',
					'username' => $anchor,
					'user_id'  => (int) ( $data['user_id'] ?? 0 ),
					'url'      => home_url( '/' ),
					'extra'    => array( 'method' => 'phonekey', 'anchor_type' => $type ),
				) );
			} elseif ( ! empty( $data['error'] ) ) {
				stp_log( 'pk_auth_fail', array(
					'sub'          => $data['error'],
					'username'     => $anchor,
					'url'          => home_url( '/wp-json/phonekey/v1/authenticate' ),
					'pk_sig_fail'  => ( $data['error'] === 'invalid_signature' ),
					'pk_locked'    => ( $data['error'] === 'locked_out' ),
					'extra'        => array( 'error' => $data['error'], 'anchor_type' => $type ),
				) );
			}
			break;

		case 'register-key':
			if ( ! empty( $data['ok'] ) ) {
				stp_log( 'pk_register', array(
					'sub'     => 'new_key',
					'username'=> $anchor,
					'user_id' => (int) ( $data['user_id'] ?? 0 ),
					'extra'   => array( 'anchor_type' => $type ),
				) );
			}
			break;

		case 'challenge':
			if ( ! empty( $data['error'] ) && $data['error'] === 'locked_out' ) {
				stp_log( 'pk_auth_fail', array(
					'sub'      => 'lockout_hit',
					'username' => $anchor,
					'pk_locked'=> true,
					'extra'    => array( 'anchor_type' => $type ),
				) );
			}
			break;
	}

	return $response;
}, 10, 3 );

// Add pk_auth, pk_auth_fail, pk_register to the risk engine
add_filter( 'stp_risk_extra', function ( $risk, $type, $ctx ) {
	$score = (int) ( $risk[0] ?? 0 );
	$reasons = is_array( $risk[1] ?? null ) ? $risk[1] : array();
	if ( $type === 'pk_auth' ) {
		// Successful PK login — apply same new-country/odd-hour logic
		if ( $ctx['new_country'] ?? false ) { $score += 35; $reasons[] = 'PK login: new country'; }
		if ( $ctx['new_ip']      ?? false ) { $score += 15; $reasons[] = 'PK login: new IP'; }
		if ( $ctx['odd_hour']    ?? false ) { $score += 20; $reasons[] = 'PK login: unusual hour'; }
	}
	if ( $type === 'pk_auth_fail' ) {
		if ( $ctx['pk_sig_fail'] ?? false ) { $score += 45; $reasons[] = 'PK: invalid cryptographic signature'; }
		if ( $ctx['pk_locked']   ?? false ) { $score += 60; $reasons[] = 'PK: account locked out'; }
		else { $score += 20; $reasons[] = 'PK: auth failure'; }
	}
	if ( $type === 'pk_register' ) {
		$score += 5; $reasons[] = 'PK: new device registration';
	}
	return array( $score, $reasons );
}, 10, 3 );


// ════════════════════════════════════════════════════════════════
//  WEBHOOK ALERT SYSTEM
//  Fires a signed JSON POST to an admin-configured URL on red events.
// ════════════════════════════════════════════════════════════════

function stp_fire_webhook( $subject, $payload ) {
	$url    = stp_webhook_url();
	$secret = stp_webhook_secret();
	if ( ! $url ) return;
	$url = stp_safe_webhook_url( $url );
	if ( ! $url ) return;

	$bucket = 'webhook|' . md5( (string) $subject );
	if ( ! stp_rate_limit( $bucket, 3, 300 ) ) return;

	$body = wp_json_encode( array_merge( $payload, array(
		'site'      => get_site_url(),
		'subject'   => $subject,
		'timestamp' => current_time( 'mysql' ),
	) ) );

	$headers = array( 'Content-Type' => 'application/json' );
	if ( $secret ) {
		$headers['X-STP-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	wp_remote_post( $url, array(
		'body'     => $body,
		'headers'  => $headers,
		'timeout'  => 2,
		'blocking' => false, // fire-and-forget
	) );
}

// Hook webhooks into the existing alert system by wrapping stp_alert
// (we can't override the original, so we use a separate shutdown action)
add_action( 'stp_webhook_fire', function ( $subject, $payload ) {
	stp_fire_webhook( $subject, $payload );
}, 10, 2 );

// ════════════════════════════════════════════════════════════════
//  CSV EXPORT
// ════════════════════════════════════════════════════════════════

function stp_export_csv() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'stp_export_csv' ) ) wp_die( 'Forbidden', '', array( 'response' => 403 ) );

	global $wpdb;

	$ff  = sanitize_text_field( $_GET['ff'] ?? '' );
	$ft  = sanitize_text_field( $_GET['ft'] ?? '' );
	$fd1 = sanitize_text_field( $_GET['fd1'] ?? date( 'Y-m-d', strtotime( '-30 days' ) ) );
	$fd2 = sanitize_text_field( $_GET['fd2'] ?? date( 'Y-m-d' ) );

	$w = array( '1=1' ); $v = array();
	if ( $ff ) { $w[] = 'e.flag_status=%s'; $v[] = $ff; }
	if ( $ft ) { $w[] = 'e.event_type=%s';  $v[] = $ft; }
	$w[] = 'DATE(e.created_at) >= %s'; $v[] = $fd1;
	$w[] = 'DATE(e.created_at) <= %s'; $v[] = $fd2;
	$ws  = implode( ' AND ', $w );

	$sql  = "SELECT e.created_at,e.event_type,e.event_sub,e.username,e.obj_type,e.obj_title,
	                e.flag_status,e.risk_score,e.risk_reasons,e.url,
	                i.ip_address,i.country,i.city,i.is_proxy
	         FROM " . stp_t( 'events' ) . " e
	         LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
	         WHERE {$ws}
	         ORDER BY e.created_at DESC LIMIT 50000";

	$rows = empty( $v )
		? $wpdb->get_results( $sql, ARRAY_A )
		: $wpdb->get_results( $wpdb->prepare( $sql, ...$v ), ARRAY_A );

	// Stream CSV
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="securetrack-events-' . date( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );
	echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

	$fh = fopen( 'php://output', 'wb' );
	fputcsv( $fh, array( 'Time','Event Type','Sub-Type','Username','Object Type','Object Title','Flag','Risk Score','Risk Reasons','URL','IP Address','Country','City','Is Proxy' ) );
	foreach ( $rows as $row ) {
		fputcsv( $fh, stp_csv_row( array(
			$row['created_at'],
			$row['event_type'],
			$row['event_sub'],
			$row['username'],
			$row['obj_type'],
			$row['obj_title'],
			$row['flag_status'],
			$row['risk_score'],
			$row['risk_reasons'],
			$row['url'],
			$row['ip_address'],
			$row['country'],
			$row['city'],
			$row['is_proxy'] ? 'Yes' : 'No',
		) ) );
	}
	fclose( $fh );
	exit;
}


// ════════════════════════════════════════════════════════════════
//  AJAX — LIVE FEED + PK ADMIN DATA
// ════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_stp_live_feed',    'stp_ajax_live_feed' );
add_action( 'wp_ajax_stp_pk_users',     'stp_ajax_pk_users' );
add_action( 'wp_ajax_stp_pk_activity',  'stp_ajax_pk_activity' );
add_action( 'wp_ajax_stp_analytics',    'stp_ajax_analytics' );

function stp_ajax_live_feed() {
	global $wpdb;
	stp_check();

	$since = (int) ( $_POST['since'] ?? 0 );
	$limit = 50;

	$sql = "SELECT e.*,i.ip_address,i.country,i.country_code,i.city,i.status AS ip_st,i.is_proxy
	        FROM " . stp_t( 'events' ) . " e
	        LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
	        WHERE e.id > %d
	        ORDER BY e.id DESC LIMIT %d";

	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $since, $limit ), ARRAY_A );
	if ( empty( $rows ) ) { wp_send_json_success( array( 'rows' => array(), 'max_id' => $since ) ); return; }

	$out = array();
	foreach ( array_reverse( $rows ) as $e ) {
		$out[] = array(
			'id'          => (int) $e['id'],
			'flag'        => $e['flag_status'],
			'time'        => date( 'H:i:s', strtotime( $e['created_at'] ) ),
			'type'        => ucwords( str_replace( '_', ' ', $e['event_type'] ) ),
			'sub'         => $e['event_sub'] ?? '',
			'user'        => $e['username'] ?? '',
			'ip'          => $e['ip_address'] ?? '',
			'loc'         => stp_location_label( $e ),
			'score'       => (int) $e['risk_score'],
			'reasons'     => substr( $e['risk_reasons'] ?? '', 0, 80 ),
			'proxy'       => (bool) $e['is_proxy'],
			'ip_status'   => $e['ip_st'] ?? 'unknown',
		);
	}

	$max = (int) $rows[0]['id'];
	wp_send_json_success( array( 'rows' => $out, 'max_id' => $max ) );
}

function stp_ajax_pk_users() {
	stp_check();
	if ( ! stp_pk_active() ) { wp_send_json_error( 'PhoneKey not active.' ); return; }
	global $wpdb;

	$type  = sanitize_key( $_POST['anchor_type'] ?? 'all' );
	$mq    = array( array( 'key' => 'pk_public_key', 'compare' => 'EXISTS' ) );
	if ( in_array( $type, array( 'email', 'phone' ), true ) )
		$mq[] = array( 'key' => 'pk_primary_anchor', 'value' => $type );

	$uids = get_users( array( 'meta_query' => $mq, 'number' => 200, 'fields' => 'ID' ) );
	$rows = array();
	foreach ( $uids as $uid ) {
		$at      = get_user_meta( $uid, 'pk_primary_anchor', true );
		$anchor  = $at === 'email'
			? get_user_meta( $uid, 'pk_email',  true )
			: get_user_meta( $uid, 'pk_phone',  true );
		$verified = $at === 'email'
			? (bool) get_user_meta( $uid, 'pk_email_verified', true )
			: (bool) get_user_meta( $uid, 'pk_phone_verified', true );

		/* Pull risk data from STP profiles */
		$profile = $wpdb->get_row( $wpdb->prepare(
			"SELECT risk_count,green_count,baseline_done FROM " . stp_t( 'profiles' ) . " WHERE user_id=%d", $uid
		), ARRAY_A );

		/* Latest event for this user */
		$last_event = $wpdb->get_var( $wpdb->prepare(
			"SELECT risk_score FROM " . stp_t( 'events' ) . " WHERE user_id=%d AND event_type LIKE 'pk_%' ORDER BY id DESC LIMIT 1", $uid
		) );

		$enrolled   = get_user_meta( $uid, 'pk_enrolled_at', true );
		$last_login = get_user_meta( $uid, 'pk_last_login',  true );
		$u          = get_userdata( $uid );

		$rows[] = array(
			'id'           => $uid,
			'display_name' => $u ? $u->display_name : "User #{$uid}",
			'anchor'       => $anchor,
			'anchor_type'  => $at,
			'verified'     => $verified,
			'mode'         => get_user_meta( $uid, 'pk_mode', true ) ?: 'B',
			'enrolled_ago' => $enrolled  ? human_time_diff( strtotime( $enrolled ) )  . ' ago' : '—',
			'last_login'   => $last_login ? human_time_diff( strtotime( $last_login ) ) . ' ago' : 'Never',
			'green_count'  => (int) ( $profile['green_count'] ?? 0 ),
			'risk_count'   => (int) ( $profile['risk_count']  ?? 0 ),
			'baseline'     => (bool) ( $profile['baseline_done'] ?? false ),
			'last_risk_score' => (int) ( $last_event ?? 0 ),
			'edit_url'     => get_edit_user_link( $uid ),
		);
	}
	wp_send_json_success( array( 'rows' => $rows ) );
}

function stp_ajax_pk_activity() {
	global $wpdb;
	stp_check();
	$filter = sanitize_key( $_POST['filter'] ?? 'all' );

	$stp_where = $filter !== 'all' ? $wpdb->prepare( "AND e.event_sub=%s", $filter ) : '';

	$stp_rows = $wpdb->get_results(
		"SELECT e.created_at,e.event_type,e.event_sub,e.username,e.flag_status,e.risk_score,
		        i.ip_address,i.country
		 FROM " . stp_t( 'events' ) . " e
		 LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE e.event_type LIKE 'pk_%' {$stp_where}
		 ORDER BY e.id DESC LIMIT 100", ARRAY_A
	);

	/* Also pull from PhoneKey's own activity table if it exists */
	$pk_rows = array();
	if ( stp_pk_active() ) {
		$pk_event_filter = ( $filter !== 'all' ) ? $wpdb->prepare( "WHERE event=%s", $filter ) : '';
		$pk_rows = $wpdb->get_results(
			"SELECT created_at, event, anchor, '' AS ip_address, '' AS country,
			        'yellow' AS flag_status, 0 AS risk_score
			 FROM " . pk_activity_table() . " {$pk_event_filter}
			 ORDER BY id DESC LIMIT 100", ARRAY_A
		);
	}

	/* Merge, sort by created_at desc, take top 150 */
	$merged = array_merge( $stp_rows, $pk_rows );
	usort( $merged, function ( $a, $b ) {
		return strcmp( $b['created_at'], $a['created_at'] );
	} );
	$merged = array_slice( $merged, 0, 150 );

	foreach ( $merged as &$row ) {
		$row['time_ago'] = human_time_diff( strtotime( $row['created_at'] ) ) . ' ago';
	}
	wp_send_json_success( array( 'rows' => $merged ) );
}

function stp_safe_returning_ip_count( $since ) {
	global $wpdb;
	$since = date( 'Y-m-d H:i:s', strtotime( (string) $since ) );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT i.id)
		 FROM " . stp_t( 'ips' ) . " i
		 LEFT JOIN " . stp_t( 'subnets' ) . " sn ON sn.subnet=CONCAT(SUBSTRING_INDEX(i.ip_address,'.',3),'.0/24')
		 LEFT JOIN (
		   SELECT DISTINCT ip_id
		   FROM " . stp_t( 'events' ) . "
		   WHERE created_at >= %s
		     AND (flag_status='red' OR risk_score>=60)
		 ) bad ON bad.ip_id=i.id
		 WHERE i.first_seen < %s
		   AND i.last_seen >= %s
		   AND i.status IN ('unknown','trusted','monitor')
		   AND COALESCE(i.risk_score,0) < 25
		   AND COALESCE(sn.is_banned,0) = 0
		   AND bad.ip_id IS NULL",
		$since, $since, $since
	) );
}

function stp_ajax_analytics() {
	global $wpdb;
	stp_check();

	$days = max( 7, min( 90, (int) ( $_POST['days'] ?? 7 ) ) );
	$since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

	/* ── Events per day ─────────────────────────────────────── */
	$daily = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(created_at) AS day, flag_status, COUNT(*) AS cnt
		 FROM " . stp_t( 'events' ) . "
		 WHERE created_at >= %s
		 GROUP BY DATE(created_at), flag_status
		 ORDER BY day ASC",
		$since
	), ARRAY_A );

	/* ── Top attacking IPs ──────────────────────────────────── */
	$top_ips = $wpdb->get_results( $wpdb->prepare(
		"SELECT i.ip_address, i.country, i.country_code, i.city, i.is_proxy, i.risk_score,
		        COUNT(e.id) AS event_cnt,
		        SUM(CASE WHEN e.flag_status='red' THEN 1 ELSE 0 END) AS red_cnt,
		        SUM(CASE WHEN e.event_type='login_failed' THEN 1 ELSE 0 END) AS fail_logins
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE e.created_at >= %s
		 GROUP BY i.id
		 ORDER BY red_cnt DESC, fail_logins DESC
		 LIMIT 10",
		$since
	), ARRAY_A
	);

	/* ── Top countries (attacks) ─────────────────────────────── */
	$top_countries = $wpdb->get_results( $wpdb->prepare(
		"SELECT i.country, i.country_code,
		        COUNT(e.id) AS event_cnt,
		        SUM(CASE WHEN e.flag_status='red' THEN 1 ELSE 0 END) AS red_cnt
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE e.created_at >= %s
		   AND i.country IS NOT NULL
		 GROUP BY i.country
		 ORDER BY red_cnt DESC, event_cnt DESC
		 LIMIT 10",
		$since
	), ARRAY_A
	);

	/* ── Event type breakdown ───────────────────────────────── */
	$by_type = $wpdb->get_results( $wpdb->prepare(
		"SELECT event_type, COUNT(*) AS cnt,
		        SUM(CASE WHEN flag_status='red' THEN 1 ELSE 0 END) AS red
		 FROM " . stp_t( 'events' ) . "
		 WHERE created_at >= %s
		 GROUP BY event_type
		 ORDER BY cnt DESC",
		$since
	), ARRAY_A
	);

    /* ── Visitor performance intelligence ───────────────────── */
	$summary = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) sessions,
		        COUNT(DISTINCT CASE WHEN user_id=0 THEN session_token END) visitors,
		        COALESCE(SUM(page_count),0) pageviews,
		        COALESCE(AVG(NULLIF(total_seconds,0)),0) avg_session_seconds
		 FROM " . stp_t( 'sessions' ) . " WHERE started_at >= %s",
		$since
	), ARRAY_A );
	$page_avg = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(AVG(NULLIF(time_spent,0)),0) FROM " . stp_t( 'pages' ) . " WHERE visited_at >= %s", $since ) );
	$top_pages = $wpdb->get_results( $wpdb->prepare(
		"SELECT url, COUNT(*) views, COALESCE(SUM(time_spent),0) seconds, COALESCE(AVG(NULLIF(time_spent,0)),0) avg_seconds
		 FROM " . stp_t( 'pages' ) . " WHERE visited_at >= %s GROUP BY url ORDER BY views DESC LIMIT 12",
		$since
	), ARRAY_A );
	$time_pages = $wpdb->get_results( $wpdb->prepare(
		"SELECT url, COUNT(*) views, COALESCE(SUM(time_spent),0) seconds, COALESCE(AVG(NULLIF(time_spent,0)),0) avg_seconds
		 FROM " . stp_t( 'pages' ) . " WHERE visited_at >= %s GROUP BY url HAVING seconds > 0 ORDER BY seconds DESC LIMIT 12",
		$since
	), ARRAY_A );
	$user_time = $wpdb->get_results( $wpdb->prepare(
		"SELECT CASE WHEN s.user_id>0 THEN COALESCE(u.display_name,u.user_login,CONCAT('User #',s.user_id)) ELSE 'visitor' END user_label,
		        COUNT(DISTINCT s.id) sessions, COALESCE(SUM(s.total_seconds),0) seconds, COALESCE(SUM(s.page_count),0) pageviews
		 FROM " . stp_t( 'sessions' ) . " s LEFT JOIN {$wpdb->users} u ON u.ID=s.user_id
		 WHERE s.started_at >= %s GROUP BY user_label ORDER BY seconds DESC LIMIT 12",
		$since
	), ARRAY_A );
	$summary['avg_page_seconds'] = $page_avg;
	$summary['new_ips'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . stp_t( 'ips' ) . " WHERE first_seen >= %s", $since ) );
	$summary['safe_returning_ips'] = stp_safe_returning_ip_count( $since );

	/* ── Login success rate ─────────────────────────────────── */
	$logins = $wpdb->get_row( $wpdb->prepare(
		"SELECT
		   SUM(CASE WHEN event_type='login_success' THEN 1 ELSE 0 END) AS success,
		   SUM(CASE WHEN event_type='login_failed'  THEN 1 ELSE 0 END) AS failed
		 FROM " . stp_t( 'events' ) . "
		 WHERE created_at >= %s",
		$since
	), ARRAY_A );

	wp_send_json_success( array(
		'daily'         => $daily,
		'top_ips'       => $top_ips,
		'top_countries' => $top_countries,
		'by_type'       => $by_type,
		'logins'        => $logins,
		'summary'       => $summary,
		'top_pages'     => $top_pages,
		'time_pages'    => $time_pages,
		'user_time'     => $user_time,
	) );
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: LIVE MONITOR
// ════════════════════════════════════════════════════════════════

function stp_pg_live() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	?>
<div class="wrap stp-wrap">
<div class="stp-hdr">
  <h1>🔴 Live Security Monitor</h1>
  <span id="stp-live-status" class="stp-live-status stp-live-on">● Watching Live</span>
  <span class="stp-tagline" id="stp-live-count">Loading…</span>
  <button id="stp-live-toggle" class="stp-rbtn">⏸ Pause</button>
  <button id="stp-live-clear"  class="stp-rbtn" style="margin-left:6px">🗑 Clear</button>
</div>

<div class="stp-live-bar">
  <label>Show only:
    <select id="stp-live-filter">
      <option value="all">All events</option>
      <option value="red">🔴 Red only</option>
      <option value="red,yellow">🔴🟡 Red + Yellow</option>
      <option value="login_failed">Failed logins</option>
    </select>
  </label>
  <label style="margin-left:18px">
    <input type="checkbox" id="stp-live-sound"> 🔔 Sound on red
  </label>
  <span class="stp-live-legend">
    <span class="stp-lleg stp-lleg-r">Red</span>
    <span class="stp-lleg stp-lleg-y">Yellow</span>
    <span class="stp-lleg stp-lleg-g">Green</span>
  </span>
</div>

<div class="stp-live-wrap">
  <table class="stp-t stp-live-tbl">
    <thead>
      <tr><th>Flag</th><th>Time</th><th>Event</th><th>User</th><th>IP / Location</th><th>Risk</th></tr>
    </thead>
    <tbody id="stp-live-body">
      <tr><td colspan="6" class="stp-emp">Connecting to live feed…</td></tr>
    </tbody>
  </table>
</div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: ANALYTICS
// ════════════════════════════════════════════════════════════════

function stp_pg_analytics() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	global $wpdb;

	/* Quick headline numbers */
	$totals = array(
		'week_events'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t('events') . " WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)" ),
		'week_red'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t('events') . " WHERE flag_status='red'  AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)" ),
		'week_attacks' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t('events') . " WHERE event_type='login_failed' AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)" ),
		'new_ips'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t('ips')    . " WHERE first_seen >= DATE_SUB(NOW(),INTERVAL 7 DAY)" ),
		'safe_returning_ips' => stp_safe_returning_ip_count( date( 'Y-m-d H:i:s', strtotime( '-7 days' ) ) ),
		'pageviews'    => (int) $wpdb->get_var( "SELECT COALESCE(SUM(page_count),0) FROM " . stp_t('sessions') . " WHERE started_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)" ),
		'avg_time'     => (int) $wpdb->get_var( "SELECT COALESCE(AVG(NULLIF(total_seconds,0)),0) FROM " . stp_t('sessions') . " WHERE started_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)" ),
	);
	?>
<div class="wrap stp-wrap">
<div class="stp-hdr">
  <h1>📊 Threat Analytics</h1>
  <span class="stp-tagline">Last 7 days</span>
  <select id="stp-an-days" onchange="stpLoadAnalytics()">
    <option value="7" selected>Last 7 days</option>
    <option value="14">Last 14 days</option>
    <option value="30">Last 30 days</option>
  </select>
</div>

<!-- HEADLINE NUMBERS -->
<div class="stp-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
  <div class="stp-card stp-card-blue"><span class="stp-n"><?php echo number_format($totals['week_events']); ?></span><span class="stp-l">Events This Week</span></div>
  <div class="stp-card stp-card-red"><span class="stp-n"><?php echo number_format($totals['week_red']); ?></span><span class="stp-l">🔴 High-Risk Events</span></div>
  <div class="stp-card stp-card-purple"><span class="stp-n"><?php echo number_format($totals['week_attacks']); ?></span><span class="stp-l">Login Attacks</span></div>
  <div class="stp-card stp-card-orange"><span class="stp-n" id="stp-kpi-newips"><?php echo number_format($totals['new_ips']); ?></span><span class="stp-l">New IPs Seen</span></div>
  <div class="stp-card stp-card-green"><span class="stp-n" id="stp-kpi-safe-returning"><?php echo number_format($totals['safe_returning_ips']); ?></span><span class="stp-l">Safe Returning IPs</span></div>
  <div class="stp-card stp-card-teal"><span class="stp-n" id="stp-kpi-pageviews"><?php echo number_format($totals['pageviews']); ?></span><span class="stp-l">Page Views</span></div>
  <div class="stp-card stp-card-green"><span class="stp-n" id="stp-kpi-avgtime"><?php echo gmdate('i:s',$totals['avg_time']); ?></span><span class="stp-l">Avg Session Time</span></div>
</div>

<!-- CHARTS AREA -->
<div class="stp-an-grid">

  <!-- Daily timeline chart (SVG rendered by JS) -->
  <div class="stp-an-card stp-an-wide">
    <h3 class="stp-an-title">Event Timeline</h3>
    <div id="stp-chart-timeline" class="stp-chart-box">
      <div class="stp-chart-loading">Loading…</div>
    </div>
  </div>

  <!-- Event type breakdown -->
  <div class="stp-an-card">
    <h3 class="stp-an-title">Event Types</h3>
    <div id="stp-chart-types" class="stp-chart-box"></div>
  </div>

  <!-- Login success rate donut -->
  <div class="stp-an-card">
    <h3 class="stp-an-title">Login Success Rate</h3>
    <div id="stp-chart-logins" class="stp-chart-box stp-chart-center"></div>
  </div>

</div>

<!-- THREAT TABLES -->
<div class="stp-an-grid" style="grid-template-columns:1fr 1fr">

  <!-- Top attacking IPs -->
  <div class="stp-an-card">
    <h3 class="stp-an-title">Top Threat IPs</h3>
    <div id="stp-top-ips"></div>
  </div>

  <!-- Top attacking countries -->
  <div class="stp-an-card">
    <h3 class="stp-an-title">Top Attack Origins</h3>
    <div id="stp-top-countries"></div>
  </div>

</div>

<!-- PERFORMANCE TABLES -->
<div class="stp-an-grid" style="grid-template-columns:1fr 1fr">
  <div class="stp-an-card">
    <h3 class="stp-an-title">Top Page Views</h3>
    <div id="stp-top-pages"></div>
  </div>
  <div class="stp-an-card">
    <h3 class="stp-an-title">Most Time Spent Pages</h3>
    <div id="stp-time-pages"></div>
  </div>
</div>

<div class="stp-an-card">
  <h3 class="stp-an-title">Time Spent By Users / Visitors</h3>
  <div id="stp-user-time"></div>
</div>

</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: AUTH SECURITY  (PhoneKey unified view)
// ════════════════════════════════════════════════════════════════

function stp_pg_auth() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$pk = stp_pk_active();
	$sodium_ok = function_exists( 'sodium_crypto_sign_verify_detached' );

	/* Save PhoneKey settings if posted */
	if ( $pk && isset( $_POST['stp_save_pk'] ) && check_admin_referer( 'stp_pk_save' ) ) {
		$pk_fields = array(
			'pk_default_country_code', 'pk_argon_domain', 'pk_hub_url', 'pk_hub_secret',
			'pk_whatsapp_phone_number', 'pk_whatsapp_token', 'pk_signup_role',
			'pk_login_page_url', 'pk_strict_mode',
		);
		foreach ( $pk_fields as $field ) {
			if ( isset( $_POST[ $field ] ) )
				update_option( $field, sanitize_text_field( $_POST[ $field ] ) );
		}
		// pk_strict_roles is an array
		$strict_roles = isset( $_POST['pk_strict_roles'] ) ? (array) $_POST['pk_strict_roles'] : array();
		update_option( 'pk_strict_roles', implode( ',', array_map( 'sanitize_key', $strict_roles ) ) );
		echo '<div class="notice notice-success is-dismissible"><p>PhoneKey settings saved.</p></div>';
	}
	?>
<div class="wrap stp-wrap">
<div class="stp-hdr">
  <h1>🔐 Auth Security</h1>
  <span class="stp-tagline">PhoneKey passwordless + behavioral analysis</span>
</div>

<!-- STATUS BADGES -->
<div class="stp-auth-badges">
  <?php
  $badges = array(
    array( 'PhoneKey Plugin',   $pk,                                               'Active',      'Not Detected'   ),
    array( 'PHP libsodium',     $sodium_ok,                                        'OK',          'MISSING!'       ),
    array( 'WhatsApp Business', $pk && ! empty( get_option('pk_whatsapp_token') ), 'Connected',   'Not connected'  ),
    array( 'Geo Resolution',    stp_cfg()['geo_enabled'],                          'Enabled',     'Disabled'       ),
    array( 'Brute Force Block', stp_cfg()['block_brute_force'],                    'Active',      'Off'            ),
    array( 'Webhook Alerts',    ! empty( stp_webhook_url() ),                     'Configured',  'Not set'        ),
  );
  foreach ( $badges as $b ) {
    $ok = (bool) $b[1];
    $bg = $ok ? '#d1fae5' : '#fee2e2'; $col = $ok ? '#065f46' : '#991b1b';
    echo "<div class='stp-auth-badge' style='background:{$bg};color:{$col}'>" .
         ( $ok ? '●' : '○' ) . " {$b[0]}: <strong>{$b[$ok ? 2 : 3]}</strong></div>";
  }
  ?>
</div>

<!-- TABS -->
<div class="stp-tab-bar">
  <button class="stp-tab active" data-tab="pk-users">👥 PhoneKey Users</button>
  <button class="stp-tab" data-tab="pk-activity">⚡ Auth Activity</button>
  <button class="stp-tab" data-tab="pk-settings">⚙️ PhoneKey Settings</button>
</div>

<!-- TAB: USERS -->
<div class="stp-tab-panel active" id="stp-tab-pk-users">
  <div class="stp-bar" style="margin-top:12px">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <select id="pk-utype">
        <option value="all">All anchor types</option>
        <option value="email">Email</option>
        <option value="phone">Phone</option>
      </select>
      <button class="button button-primary" id="pk-load-users">▶ Load Users</button>
      <span id="pk-user-count" class="stp-tot"></span>
    </div>
  </div>
  <div id="pk-users-out"></div>
</div>

<!-- TAB: ACTIVITY -->
<div class="stp-tab-panel" id="stp-tab-pk-activity">
  <div class="stp-bar" style="margin-top:12px">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <select id="pk-afilter">
        <option value="all">All events</option>
        <option value="success">Successful auth</option>
        <option value="invalid_signature">Signature failures</option>
        <option value="locked_out">Lockouts</option>
        <option value="new_key">New registrations</option>
      </select>
      <button class="button button-primary" id="pk-load-act">▶ Load Activity</button>
      <span id="pk-act-count" class="stp-tot"></span>
    </div>
  </div>
  <div id="pk-act-out"></div>
</div>

<!-- TAB: SETTINGS -->
<div class="stp-tab-panel" id="stp-tab-pk-settings">
<?php if ( ! $pk ): ?>
  <div class="stp-ph-notice">
    <h3>PhoneKey Not Detected</h3>
    <p>Install and activate the <strong>PhoneKey</strong> plugin to manage passwordless authentication settings here.</p>
    <p>Once installed, this panel will display all PhoneKey configuration options merged within this unified security dashboard.</p>
  </div>
<?php else: ?>
  <form method="post" style="margin-top:16px">
    <?php wp_nonce_field('stp_pk_save'); ?>
    <table class="form-table stp-stbl">
      <tr>
        <th>Argon2id Domain Salt</th>
        <td>
          <input type="text" name="pk_argon_domain" value="<?php echo esc_attr( get_option('pk_argon_domain') ?: wp_parse_url(home_url(),PHP_URL_HOST) ); ?>" class="regular-text">
          <p class="description">Used for key derivation. <strong>Do not change after users have enrolled.</strong></p>
        </td>
      </tr>
      <tr>
        <th>Default Country Code</th>
        <td>
          <input type="text" name="pk_default_country_code" value="<?php echo esc_attr( get_option('pk_default_country_code','+1') ); ?>" style="width:80px">
          <p class="description">Prepended to numbers without a code (e.g. +44, +91, +92).</p>
        </td>
      </tr>
      <tr>
        <th>Custom Login Page URL</th>
        <td>
          <input type="url" name="pk_login_page_url" value="<?php echo esc_attr( get_option('pk_login_page_url') ); ?>" class="regular-text" placeholder="https://yoursite.com/login">
          <p class="description">Redirects <code>wp-login.php</code> here if set.</p>
        </td>
      </tr>
      <tr>
        <th>Default Signup Role</th>
        <td>
          <select name="pk_signup_role">
            <option value="">— WordPress default (<?php echo esc_html( get_option('default_role','subscriber') ); ?>) —</option>
            <?php foreach ( wp_roles()->get_names() as $slug => $name ): ?>
              <option value="<?php echo esc_attr($slug); ?>" <?php selected( get_option('pk_signup_role'), $slug ); ?>><?php echo esc_html($name); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th>Strict 2FA Mode</th>
        <td>
          <label><input type="checkbox" name="pk_strict_mode" value="1" <?php checked( get_option('pk_strict_mode'), '1' ); ?>>
          Require email OTP after login for selected roles</label>
        </td>
      </tr>
      <tr>
        <th>WhatsApp Hub URL</th>
        <td>
          <input type="url" name="pk_hub_url" value="<?php echo esc_attr( get_option('pk_hub_url') ); ?>" class="regular-text" placeholder="https://hub.yourdomain.com">
        </td>
      </tr>
      <tr>
        <th>WhatsApp Hub Secret</th>
        <td><input type="password" name="pk_hub_secret" value="<?php echo esc_attr( get_option('pk_hub_secret') ); ?>" class="regular-text"></td>
      </tr>
      <tr>
        <th>WA Phone Number ID</th>
        <td><input type="text" name="pk_whatsapp_phone_number" value="<?php echo esc_attr( get_option('pk_whatsapp_phone_number') ); ?>" class="regular-text"></td>
      </tr>
      <tr>
        <th>WA Access Token</th>
        <td><input type="password" name="pk_whatsapp_token" value="<?php echo esc_attr( get_option('pk_whatsapp_token') ); ?>" class="regular-text"></td>
      </tr>
    </table>
    <p class="submit"><input type="submit" name="stp_save_pk" class="button-primary" value="Save PhoneKey Settings"></p>
  </form>
<?php endif; ?>
</div><!-- /stp-tab-pk-settings -->
</div><!-- .stp-wrap -->
<?php
}


// ════════════════════════════════════════════════════════════════
//  WEBHOOK SETTING  (added to existing settings page via hook)
// ════════════════════════════════════════════════════════════════

add_action( 'stp_settings_extra_fields', function () {
	?>
  <tr>
    <th scope="row">Webhook Alerts</th>
    <td>
      Webhook URL: <input type="url" name="stp_webhook_url" value="" style="width:320px" placeholder="<?php echo esc_attr( stp_webhook_url() ? 'Webhook stored - leave blank to keep' : 'https://hooks.slack.com/services/...' ); ?>"><br>
      HMAC secret: <input type="password" name="stp_webhook_secret" value="" style="width:260px" placeholder="<?php echo esc_attr( stp_webhook_secret() ? 'Secret stored - leave blank to keep' : 'optional signing secret' ); ?>" autocomplete="new-password">
      <?php if ( stp_webhook_url() || stp_webhook_secret() ): ?><label style="margin-left:10px"><input type="checkbox" name="stp_webhook_clear"> Clear stored webhook</label><?php endif; ?>
      <p class="description">A signed JSON POST is fired on every 🔴 red-flag event. Verify with <code>X-STP-Signature: sha256=HMAC</code>.</p>
    </td>
  </tr>
	<?php
} );

// Save webhook settings alongside main settings form
add_action( 'updated_option', function ( $opt ) {
	if ( $opt !== 'stp_settings' ) return;
	if ( ! empty( $_POST['stp_webhook_clear'] ) ) {
		delete_option( 'stp_webhook_url_enc' );
		delete_option( 'stp_webhook_secret_enc' );
		update_option( 'stp_webhook_url', '', false );
		update_option( 'stp_webhook_secret', '', false );
	} else {
		$new_webhook_url = stp_safe_webhook_url( trim( (string) ( $_POST['stp_webhook_url'] ?? '' ) ) );
		$new_webhook_secret = sanitize_text_field( trim( (string) ( $_POST['stp_webhook_secret'] ?? '' ) ) );
		if ( $new_webhook_url !== '' ) {
			if ( stp_update_encrypted_option( 'stp_webhook_url_enc', $new_webhook_url ) ) update_option( 'stp_webhook_url', '', false );
		}
		if ( $new_webhook_secret !== '' ) {
			if ( stp_update_encrypted_option( 'stp_webhook_secret_enc', $new_webhook_secret ) ) update_option( 'stp_webhook_secret', '', false );
		}
	}
} );


// ════════════════════════════════════════════════════════════════
//  EXTENDED CSS
// ════════════════════════════════════════════════════════════════

function stp_ext_css() {
	return '
/* ── Live Monitor ─────────────────────────────────────────── */
.stp-live-status{display:inline-block;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;margin-left:auto}
.stp-live-on{background:#d1fae5;color:#065f46;animation:stp-pulse 2s infinite}
.stp-live-off{background:#f1f5f9;color:#64748b}
@keyframes stp-pulse{0%,100%{opacity:1}50%{opacity:.6}}
.stp-live-bar{display:flex;align-items:center;gap:18px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:14px;flex-wrap:wrap}
.stp-live-bar select{padding:4px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:13px}
.stp-live-legend{display:flex;gap:8px;margin-left:auto}
.stp-lleg{padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700}
.stp-lleg-r{background:#fee2e2;color:#dc2626}
.stp-lleg-y{background:#fef3c7;color:#d97706}
.stp-lleg-g{background:#d1fae5;color:#059669}
.stp-live-wrap{border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow-x:auto;max-height:680px;overflow-y:auto}
.stp-live-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.stp-live-tbl th{background:#0f172a;color:#e2e8f0;padding:8px 11px;text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.7px;position:sticky;top:0;z-index:2}
.stp-live-tbl td{padding:8px 11px;border-bottom:1px solid #f1f5f9;background:#fff;vertical-align:middle}
.stp-live-new td{animation:stp-flash .8s ease-out}
@keyframes stp-flash{0%{background:#fef08a}100%{background:inherit}}

/* ── Analytics ────────────────────────────────────────────── */
.stp-an-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:18px}
.stp-an-card{background:#fff;border-radius:10px;padding:16px 18px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
.stp-an-wide{grid-column:1/-1}
.stp-an-title{margin:0 0 14px;font-size:13px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:.5px}
.stp-chart-box{min-height:160px;overflow:hidden}
.stp-chart-center{display:flex;align-items:center;justify-content:center}
.stp-chart-loading{color:#94a3b8;padding:40px;text-align:center;font-size:13px}

/* ── Auth Security ────────────────────────────────────────── */
.stp-auth-badges{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.stp-auth-badge{padding:6px 14px;border-radius:20px;font-size:12px}
.stp-tab-bar{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:0}
.stp-tab{padding:9px 18px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;color:#64748b;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s}
.stp-tab.active,.stp-tab:hover{color:#0f172a;border-bottom-color:#3b82f6}
.stp-tab-panel{display:none;background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:16px 18px}
.stp-tab-panel.active{display:block}
.stp-ph-notice{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:20px 24px;margin:16px 0}
.stp-ph-notice h3{margin:0 0 8px;color:#92400e}

/* ── PK Users / Activity tables ──────────────────────────── */
.stp-pk-tbl{width:100%;border-collapse:collapse;font-size:12.5px;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.stp-pk-tbl th{background:#1e293b;color:#e2e8f0;padding:8px 12px;text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.7px}
.stp-pk-tbl td{padding:8px 12px;border-bottom:1px solid #f1f5f9;background:#fff;vertical-align:middle;font-size:12.5px}
.stp-pk-tbl tr:last-child td{border-bottom:none}
.stp-vfy-y{display:inline-block;background:#d1fae5;color:#065f46;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600}
.stp-vfy-n{display:inline-block;background:#fee2e2;color:#991b1b;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600}
.stp-pk-risk-low{color:#059669;font-weight:700}
.stp-pk-risk-mid{color:#d97706;font-weight:700}
.stp-pk-risk-hi{color:#dc2626;font-weight:700}

/* ── Threat tables (analytics) ────────────────────────────── */
.stp-threat-tbl{width:100%;border-collapse:collapse;font-size:12px}
.stp-threat-tbl th{padding:7px 10px;background:#f8fafc;font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;text-align:left}
.stp-threat-tbl td{padding:7px 10px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.stp-threat-bar-wrap{height:6px;background:#f1f5f9;border-radius:3px;width:100%;overflow:hidden;margin-top:2px}
.stp-threat-bar{height:6px;border-radius:3px;background:#ef4444;transition:width .4s}
.stp-flag-emoji{font-size:14px;margin-right:4px}
';
}


// ════════════════════════════════════════════════════════════════
//  EXTENDED JAVASCRIPT
// ════════════════════════════════════════════════════════════════

function stp_ext_js() {
	return '
jQuery(function($){

  var NONCE   = (typeof stpCfg!=="undefined") ? stpCfg.nonce   : "";
  var AJAXURL = (typeof stpCfg!=="undefined") ? stpCfg.ajaxurl : "/wp-admin/admin-ajax.php";

  function post(action,data,cb){
    $.post(AJAXURL,$.extend({action:action,nonce:NONCE},data),function(r){
      if(r.success){ if(cb) cb(r.data); }
      else console.warn("STP:",r.data);
    },"json");
  }

  function esc(s){
    return String(s||"").replace(/[&<>\"=\/]/g,function(c){
      return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","=":"&#61;","/":"&#47;"}[c];
    });
  }
  function ipLink(ip){
    ip=String(ip||"");
    if(!ip) return "";
    return "<a class=\"stp-ip\" href=\"admin.php?page=stp-chain&ip="+encodeURIComponent(ip)+"\"><code>"+esc(ip)+"</code></a>";
  }

  /* ═══════════════════════════════════════════
     LIVE MONITOR
  ═══════════════════════════════════════════ */
  if($("#stp-live-body").length){
    var liveMaxId=0, livePaused=false, liveInterval=null;
    var soundCtx=null;

    function beep(){
      try{
        if(!soundCtx) soundCtx=new (window.AudioContext||window.webkitAudioContext)();
        var o=soundCtx.createOscillator(),g=soundCtx.createGain();
        o.connect(g); g.connect(soundCtx.destination);
        o.frequency.value=880; g.gain.setValueAtTime(.3,soundCtx.currentTime);
        g.gain.exponentialRampToValueAtTime(.001,soundCtx.currentTime+.4);
        o.start(); o.stop(soundCtx.currentTime+.4);
      }catch(e){}
    }

    function fetchLive(){
      if(livePaused) return;
      var filter=$("#stp-live-filter").val()||"all";
      post("stp_live_feed",{since:liveMaxId},function(d){
        if(!d.rows||!d.rows.length) return;
        liveMaxId=d.max_id;
        var $tbody=$("#stp-live-body");
        var wasEmpty=$tbody.find(".stp-emp").length>0;
        if(wasEmpty) $tbody.empty();

        var filterVals=filter==="all"?null:filter.split(",");
        var added=0;

        d.rows.forEach(function(e){
          if(filterVals){
            var match=filterVals.some(function(f){
              return e.flag===f || e.type.toLowerCase().replace(/ /g,"_")===f;
            });
            if(!match) return;
          }

          var fi={"green":"🟢","yellow":"🟡","red":"🔴","blocked":"⛔"}[e.flag]||"⚪";
          var rc=e.score>=60?"rr":(e.score>=25?"ry":"rg");
          var loc=e.loc||"—";
          var proxy=e.proxy?" <span class=\"stp-tag stp-tag-p\">proxy</span>":"";
          var row=$("<tr class=\"stp-live-new stp-r-"+esc(e.flag)+"\">").html(
            "<td class=\"stp-fc\">"+fi+"</td>"+
            "<td class=\"stp-tc\"><small>"+esc(e.time)+"</small></td>"+
            "<td><strong>"+esc(e.type)+"</strong>"+
              (e.sub?"<em class=\"stp-sub2\"> ("+esc(e.sub)+")</em>":"")+
              (e.reasons?"<br><small class=\"stp-rr\">"+esc(e.reasons)+"</small>":"")+
            "</td>"+
            "<td>"+(e.user?"<strong>"+esc(e.user)+"</strong>":"<em class=\"stp-vis\">visitor</em>")+"</td>"+
            "<td>"+ipLink(e.ip)+proxy+"<br><small>"+esc(loc)+"</small>"+
              "<br><small class=\"stp-ist stp-ist-"+esc(e.ip_status)+"\">"+esc(e.ip_status)+"</small>"+
            "</td>"+
            "<td><span class=\"stp-sc "+rc+"\">"+e.score+"</span></td>"
          );

          $tbody.prepend(row);
          added++;

          if(e.flag==="red" && $("#stp-live-sound").is(":checked")) beep();
        });

        // Keep max 200 rows visible
        var $rows=$tbody.find("tr");
        if($rows.length>200) $rows.slice(200).remove();

        if(added>0){
          var total=$rows.length;
          $("#stp-live-count").text(total+" events shown | last update: "+new Date().toLocaleTimeString());
        }
      });
    }

    function startLive(){
      if(liveInterval) clearInterval(liveInterval);
      fetchLive();
      liveInterval=setInterval(fetchLive,10000);
      livePaused=false;
      $("#stp-live-status").removeClass("stp-live-off").addClass("stp-live-on").text("● Watching Live");
      $("#stp-live-toggle").text("⏸ Pause");
    }

    function pauseLive(){
      clearInterval(liveInterval); liveInterval=null;
      livePaused=true;
      $("#stp-live-status").removeClass("stp-live-on").addClass("stp-live-off").text("○ Paused");
      $("#stp-live-toggle").text("▶ Resume");
    }

    $("#stp-live-toggle").on("click",function(){ livePaused?startLive():pauseLive(); });
    $("#stp-live-clear").on("click",function(){ $("#stp-live-body").html("<tr><td colspan=\"6\" class=\"stp-emp\">Feed cleared. New events will appear in 10s.</td></tr>"); liveMaxId=0; });
    $("#stp-live-filter").on("change",function(){ if(!livePaused) fetchLive(); });

    startLive();
  }

  /* ═══════════════════════════════════════════
     ANALYTICS PAGE
  ═══════════════════════════════════════════ */
  function stpLoadAnalytics(){
    var days=parseInt($("#stp-an-days").val()||"7",10);
    post("stp_analytics",{days:days},function(d){
      renderTimeline(d.daily,days);
      renderTypes(d.by_type);
      renderLogins(d.logins);
      renderTopIPs(d.top_ips);
      renderCountries(d.top_countries);
      renderSummary(d.summary);
      renderPages("#stp-top-pages",d.top_pages,"views");
      renderPages("#stp-time-pages",d.time_pages,"seconds");
      renderUserTime(d.user_time);
    });
  }
  window.stpLoadAnalytics=stpLoadAnalytics;
  if($("#stp-chart-timeline").length) stpLoadAnalytics();

  /* Bar chart — events per day by flag */
  function renderTimeline(daily,days){
    var buckets={};
    // Build date range
    for(var i=days-1;i>=0;i--){
      var d=new Date(); d.setDate(d.getDate()-i);
      var k=d.toISOString().slice(0,10);
      buckets[k]={green:0,yellow:0,red:0};
    }
    daily.forEach(function(row){
      if(buckets[row.day]) buckets[row.day][row.flag_status]=(buckets[row.day][row.flag_status]||0)+parseInt(row.cnt,10);
    });
    var dates=Object.keys(buckets).sort();
    var maxVal=0;
    dates.forEach(function(d){ var s=buckets[d].green+buckets[d].yellow+buckets[d].red; if(s>maxVal) maxVal=s; });
    if(maxVal===0) maxVal=1;

    var W=Math.max(600,dates.length*54), H=140, pad=12, bw=Math.min(44,Math.floor((W-pad*2)/dates.length)-4);
    var svg="<svg viewBox=\"0 0 "+W+" "+H+"\" xmlns=\"http://www.w3.org/2000/svg\" style=\"width:100%;height:140px\">";

    dates.forEach(function(day,i){
      var x=pad+i*((W-pad*2)/dates.length)+(((W-pad*2)/dates.length)-bw)/2;
      var g=buckets[day].green, y2=buckets[day].yellow, r=buckets[day].red;
      var total=g+y2+r;
      var y=H-20;
      var rH=Math.max(total?2:0,(r/maxVal)*(H-30));
      var yH=Math.max(y2&&!r?2:0,(y2/maxVal)*(H-30));
      var gH=Math.max(g&&!r&&!y2?2:0,(g/maxVal)*(H-30));
      // stack bars
      if(rH){ svg+="<rect x=\""+x+"\" y=\""+(y-rH)+"\" width=\""+bw+"\" height=\""+rH+"\" rx=\"3\" fill=\"#ef4444\" opacity=\".85\"/>"; }
      if(yH){ svg+="<rect x=\""+x+"\" y=\""+(y-rH-yH)+"\" width=\""+bw+"\" height=\""+yH+"\" rx=\"3\" fill=\"#f59e0b\" opacity=\".85\"/>"; }
      if(gH){ svg+="<rect x=\""+x+"\" y=\""+(y-rH-yH-gH)+"\" width=\""+bw+"\" height=\""+gH+"\" rx=\"3\" fill=\"#10b981\" opacity=\".7\"/>"; }
      // date label
      var label=day.slice(5); // MM-DD
      svg+="<text x=\""+(x+bw/2)+"\" y=\""+(H-4)+"\" text-anchor=\"middle\" font-size=\"10\" fill=\"#94a3b8\">"+label+"</text>";
      // count label
      if(total) svg+="<text x=\""+(x+bw/2)+"\" y=\""+(y-rH-yH-gH-4)+"\" text-anchor=\"middle\" font-size=\"10\" font-weight=\"700\" fill=\"#334155\">"+total+"</text>";
    });
    svg+="</svg>";
    $("#stp-chart-timeline").html(svg);
  }

  /* Horizontal bar chart — event types */
  function renderTypes(types){
    if(!types||!types.length){ $("#stp-chart-types").html("<div class=\"stp-chart-loading\">No data</div>"); return; }
    var max=parseInt(types[0].cnt,10)||1;
    var html="<table class=\"stp-threat-tbl\">"
      +"<thead><tr><th>Type</th><th>Count</th><th>Red</th></tr></thead><tbody>";
    types.slice(0,8).forEach(function(t){
      var pct=Math.round(parseInt(t.cnt,10)/max*100);
      html+="<tr>"
        +"<td>"+esc(t.event_type.replace(/_/g," "))+"<div class=\"stp-threat-bar-wrap\"><div class=\"stp-threat-bar\" style=\"width:"+pct+"%;background:#3b82f6\"></div></div></td>"
        +"<td><strong>"+esc(t.cnt)+"</strong></td>"
        +"<td class=\"rr\">"+esc(t.red)+"</td>"
        +"</tr>";
    });
    html+="</tbody></table>";
    $("#stp-chart-types").html(html);
  }

  /* Donut-style SVG for login rate */
  function renderLogins(logins){
    var s=parseInt((logins&&logins.success)||0,10);
    var f=parseInt((logins&&logins.failed)||0,10);
    var total=s+f||1;
    var pct=Math.round(s/total*100);
    var r=50, cx=70, cy=70, circum=2*Math.PI*r;
    var dashS=circum*(s/total), dashF=circum-dashS;
    var svg="<svg viewBox=\"0 0 140 140\" style=\"width:140px;height:140px\">"
      +"<circle cx=\""+cx+"\" cy=\""+cy+"\" r=\""+r+"\" fill=\"none\" stroke=\"#f1f5f9\" stroke-width=\"18\"/>"
      +"<circle cx=\""+cx+"\" cy=\""+cy+"\" r=\""+r+"\" fill=\"none\" stroke=\"#10b981\" stroke-width=\"18\" "
      +"stroke-dasharray=\""+dashS+" "+dashF+"\" stroke-dashoffset=\""+circum/4+"\" stroke-linecap=\"round\"/>"
      +"<text x=\""+cx+"\" y=\""+(cy-8)+"\" text-anchor=\"middle\" font-size=\"22\" font-weight=\"800\" fill=\"#0f172a\">"+pct+"%</text>"
      +"<text x=\""+cx+"\" y=\""+(cy+12)+"\" text-anchor=\"middle\" font-size=\"11\" fill=\"#64748b\">success rate</text>"
      +"<text x=\""+cx+"\" y=\""+(cy+28)+"\" text-anchor=\"middle\" font-size=\"10\" fill=\"#94a3b8\">"+s+" ok / "+f+" fail</text>"
      +"</svg>";
    $("#stp-chart-logins").html(svg);
  }

  function fmtSecs(sec){
    sec=parseInt(sec||0,10);
    var m=Math.floor(sec/60), s=sec%60;
    if(m>=60){ var h=Math.floor(m/60); return h+"h "+(m%60)+"m"; }
    return m+":"+(s<10?"0":"")+s;
  }

  function renderSummary(s){
    if(!s) return;
    $("#stp-kpi-newips").text(parseInt(s.new_ips||0,10).toLocaleString());
    $("#stp-kpi-safe-returning").text(parseInt(s.safe_returning_ips||0,10).toLocaleString());
    $("#stp-kpi-pageviews").text(parseInt(s.pageviews||0,10).toLocaleString());
    $("#stp-kpi-avgtime").text(fmtSecs(s.avg_session_seconds||0));
  }

  function renderPages(sel,rows,mode){
    if(!rows||!rows.length){ $(sel).html("<div class=\"stp-chart-loading\">No page data yet</div>"); return; }
    var max=Math.max.apply(null,rows.map(function(r){ return parseInt(mode==="views"?r.views:r.seconds,10)||0; }))||1;
    var html="<table class=\"stp-threat-tbl\"><thead><tr><th>Page</th><th>Views</th><th>Total Time</th><th>Avg</th></tr></thead><tbody>";
    rows.forEach(function(r){
      var path=(r.url||"/").replace(/^https?:\/\/[^\/]+/,"")||"/";
      var val=parseInt(mode==="views"?r.views:r.seconds,10)||0;
      var pct=Math.max(3,Math.round(val/max*100));
      html+="<tr><td><code>"+esc(path.slice(0,80))+"</code><div class=\"stp-threat-bar-wrap\"><div class=\"stp-threat-bar\" style=\"width:"+pct+"%;background:#14b8a6\"></div></div></td><td><strong>"+esc(r.views)+"</strong></td><td>"+fmtSecs(r.seconds)+"</td><td>"+fmtSecs(r.avg_seconds)+"</td></tr>";
    });
    html+="</tbody></table>";
    $(sel).html(html);
  }

  function renderUserTime(rows){
    if(!rows||!rows.length){ $("#stp-user-time").html("<div class=\"stp-chart-loading\">No session time yet</div>"); return; }
    var max=Math.max.apply(null,rows.map(function(r){ return parseInt(r.seconds,10)||0; }))||1;
    var html="<table class=\"stp-threat-tbl\"><thead><tr><th>User / Visitor</th><th>Sessions</th><th>Page Views</th><th>Time</th></tr></thead><tbody>";
    rows.forEach(function(r){
      var pct=Math.max(3,Math.round((parseInt(r.seconds,10)||0)/max*100));
      html+="<tr><td>"+esc(r.user_label||"visitor")+"<div class=\"stp-threat-bar-wrap\"><div class=\"stp-threat-bar\" style=\"width:"+pct+"%;background:#6366f1\"></div></div></td><td>"+esc(r.sessions)+"</td><td>"+esc(r.pageviews)+"</td><td><strong>"+fmtSecs(r.seconds)+"</strong></td></tr>";
    });
    html+="</tbody></table>";
    $("#stp-user-time").html(html);
  }

  /* Top IPs table */
  function renderTopIPs(ips){
    if(!ips||!ips.length){ $("#stp-top-ips").html("<div class=\"stp-chart-loading\">No data</div>"); return; }
    var maxRed=Math.max.apply(null,ips.map(function(r){ return parseInt(r.red_cnt,10)||0; }))||1;
    var html="<table class=\"stp-threat-tbl\"><thead><tr><th>IP Address</th><th>Country</th><th>Risk</th><th>🔴 Red</th><th>Fail Logins</th></tr></thead><tbody>";
    ips.forEach(function(ip){
      var pct=Math.round(parseInt(ip.red_cnt,10)/maxRed*100);
      var proxy=ip.is_proxy?"<span class=\"stp-tag stp-tag-p\">px</span>":"";
      html+="<tr>"
        +"<td>"+ipLink(ip.ip_address)+proxy+
           "<div class=\"stp-threat-bar-wrap\"><div class=\"stp-threat-bar\" style=\"width:"+pct+"%\"></div></div></td>"
        +"<td><small>"+esc(ip.country||"—")+"</small></td>"
        +"<td><span class=\"stp-sc "+(ip.risk_score>=60?"rr":ip.risk_score>=25?"ry":"rg")+"\">"+ip.risk_score+"</span></td>"
        +"<td class=\"rr\"><strong>"+ip.red_cnt+"</strong></td>"
        +"<td>"+ip.fail_logins+"</td>"
        +"</tr>";
    });
    html+="</tbody></table>";
    $("#stp-top-ips").html(html);
  }

  /* Top countries table */
  function renderCountries(countries){
    if(!countries||!countries.length){ $("#stp-top-countries").html("<div class=\"stp-chart-loading\">No data</div>"); return; }
    var maxE=Math.max.apply(null,countries.map(function(r){ return parseInt(r.event_cnt,10)||0; }))||1;
    var flagBase="https://flagcdn.com/16x12/";
    var html="<table class=\"stp-threat-tbl\"><thead><tr><th>Country</th><th>Events</th><th>🔴 Red</th></tr></thead><tbody>";
    countries.forEach(function(c){
      var pct=Math.round(parseInt(c.event_cnt,10)/maxE*100);
      var flagImg=c.country_code?"<img src=\""+flagBase+c.country_code.toLowerCase()+".png\" width=\"16\" height=\"12\" style=\"vertical-align:middle;margin-right:5px\">":"";
      html+="<tr>"
        +"<td>"+flagImg+"<span>"+esc(c.country||"Unknown")+"</span>"+
           "<div class=\"stp-threat-bar-wrap\"><div class=\"stp-threat-bar\" style=\"width:"+pct+"%;background:#8b5cf6\"></div></div></td>"
        +"<td><strong>"+esc(c.event_cnt)+"</strong></td>"
        +"<td class=\"rr\">"+esc(c.red_cnt)+"</td>"
        +"</tr>";
    });
    html+="</tbody></table>";
    $("#stp-top-countries").html(html);
  }

  /* ═══════════════════════════════════════════
     AUTH SECURITY — Tabs + Data loading
  ═══════════════════════════════════════════ */
  if($(".stp-tab-bar").length){
    $(".stp-tab").on("click",function(){
      var target=$(this).data("tab");
      $(".stp-tab").removeClass("active");
      $(".stp-tab-panel").removeClass("active");
      $(this).addClass("active");
      $("#stp-tab-"+target).addClass("active");
    });

    /* PK Users */
    $("#pk-load-users").on("click",function(){
      var $btn=$(this).prop("disabled",true).text("Loading…");
      post("stp_pk_users",{anchor_type:$("#pk-utype").val()},function(d){
        $btn.prop("disabled",false).text("▶ Load Users");
        $("#pk-user-count").text(d.rows.length+" user(s)");
        if(!d.rows.length){ $("#pk-users-out").html("<div class=\"stp-chart-loading\">No PhoneKey users found.</div>"); return; }
        var html="<table class=\"stp-pk-tbl\">"
          +"<thead><tr><th>User</th><th>Anchor</th><th>Type</th><th>Verified</th><th>🟢 Clean</th><th>🔴 Risk</th><th>Baseline</th><th>Last Risk Score</th><th>Enrolled</th><th>Last Login</th></tr></thead><tbody>";
        d.rows.forEach(function(r,i){
          var vBadge=r.verified?"<span class=\"stp-vfy-y\">✓ Yes</span>":"<span class=\"stp-vfy-n\">No</span>";
          var rClass=r.last_risk_score>=60?"stp-pk-risk-hi":(r.last_risk_score>=25?"stp-pk-risk-mid":"stp-pk-risk-low");
          html+="<tr style=\"background:"+(i%2?"#fafafa":"#fff")+"\">"
            +"<td><a href=\""+esc(r.edit_url)+"\" target=\"_blank\">"+esc(r.display_name)+"</a></td>"
            +"<td><code>"+esc(r.anchor)+"</code></td>"
            +"<td><span class=\"stp-tag\">"+esc(r.anchor_type)+"</span></td>"
            +"<td>"+vBadge+"</td>"
            +"<td class=\"rg\"><strong>"+r.green_count+"</strong></td>"
            +"<td class=\"rr\">"+r.risk_count+"</td>"
            +"<td>"+(r.baseline?"<span class=\"stp-tag stp-tag-ok\">✓ Ready</span>":"<span class=\"stp-tag\">Learning</span>")+"</td>"
            +"<td class=\""+rClass+"\">"+r.last_risk_score+"</td>"
            +"<td><small>"+esc(r.enrolled_ago)+"</small></td>"
            +"<td><small>"+esc(r.last_login)+"</small></td>"
            +"</tr>";
        });
        html+="</tbody></table>";
        $("#pk-users-out").html(html);
      });
    });

    /* PK Activity */
    $("#pk-load-act").on("click",function(){
      var $btn=$(this).prop("disabled",true).text("Loading…");
      post("stp_pk_activity",{filter:$("#pk-afilter").val()},function(d){
        $btn.prop("disabled",false).text("▶ Load Activity");
        $("#pk-act-count").text(d.rows.length+" event(s)");
        var colors={success:"#059669",invalid_signature:"#dc2626",lockout_hit:"#dc2626",new_key:"#2563eb",login_success:"#059669",sig_invalid:"#dc2626"};
        var html="<table class=\"stp-pk-tbl\">"
          +"<thead><tr><th>Time</th><th>Event</th><th>Anchor / User</th><th>IP</th><th>Country</th><th>Flag</th><th>Risk</th></tr></thead><tbody>";
        d.rows.forEach(function(r,i){
          var evKey=(r.event_sub||r.event||"").toLowerCase();
          var col=colors[evKey]||"#555";
          var fi={"green":"🟢","yellow":"🟡","red":"🔴"}[r.flag_status]||"⚪";
          html+="<tr style=\"background:"+(i%2?"#fafafa":"#fff")+"\">"
            +"<td><small>"+esc(r.time_ago)+"</small></td>"
            +"<td style=\"color:"+col+";font-weight:600\">"+esc((r.event_type||r.event||"").replace(/_/g," "))+(r.event_sub?" <em>("+esc(r.event_sub)+")</em>":"")+"</td>"
            +"<td><code>"+esc(r.anchor||r.username||"—")+"</code></td>"
            +"<td><small>"+(r.ip_address?ipLink(r.ip_address):"—")+"</small></td>"
            +"<td><small>"+esc(r.country||"—")+"</small></td>"
            +"<td>"+fi+"</td>"
            +"<td><span class=\"stp-sc "+(r.risk_score>=60?"rr":r.risk_score>=25?"ry":"rg")+"\">"+esc(r.risk_score)+"</span></td>"
            +"</tr>";
        });
        html+="</tbody></table>";
        $("#pk-act-out").html(html);
      });
    });
  }

});
';
}

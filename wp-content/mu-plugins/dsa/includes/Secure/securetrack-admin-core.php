<?php

if ( ! defined( 'ABSPATH' ) ) {
	 exit;
}

require_once __DIR__ . '/SecureTrack_Admin_Service.php';

//  ADMIN MENU
// ════════════════════════════════════════════════════════════════

add_action( 'admin_menu', function () {
	\DSA\Secure\SecureTrack_Admin_Service::register_core_menus();
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	\DSA\Secure\SecureTrack_Admin_Service::enqueue_core_assets( $hook );
} );

add_action( 'admin_head', function () {
	\DSA\Secure\SecureTrack_Admin_Service::render_menu_badge_css();
} );


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: EVENTS LOG
// ════════════════════════════════════════════════════════════════

function stp_pg_events() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;

	/* Stats */
	$st = \DSA\Secure\SecureTrack_Admin_Data_Service::events_overview_counts();

	/* Filters */
	$ft = sanitize_text_field( $_GET['ft'] ?? '' );
	$ff = sanitize_text_field( $_GET['ff'] ?? '' );
	$fu = sanitize_text_field( $_GET['fu'] ?? '' );
	$fi = sanitize_text_field( $_GET['fi'] ?? '' );
	$fd = sanitize_text_field( $_GET['fd'] ?? '' );
	$fa = sanitize_text_field( $_GET['fa'] ?? '' );
	$pn = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$pp = 25; $off = ( $pn - 1 ) * $pp;

	$w = array( '1=1' ); $v = array();
	if ( $ft ) { $w[] = 'e.event_type=%s';     $v[] = $ft; }
	if ( $ff ) { $w[] = 'e.flag_status=%s';    $v[] = $ff; }
	if ( $fu ) { $w[] = 'e.username LIKE %s';  $v[] = '%' . $wpdb->esc_like( $fu ) . '%'; }
	if ( $fi ) { $w[] = 'i.ip_address LIKE %s';$v[] = '%' . $wpdb->esc_like( $fi ) . '%'; }
	if ( $fd ) { $w[] = 'DATE(e.created_at)=%s'; $v[] = $fd; }
	if ( $fa === 'visitor' ) { $w[] = 'e.user_id=0'; }
	if ( $fa === 'user' ) { $w[] = 'e.user_id>0'; }
	$ws   = implode( ' AND ', $w );
	$base = "FROM " . stp_t( 'events' ) . " e
		LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		LEFT JOIN " . stp_t( 'subnets' ) . " sn ON sn.subnet=CONCAT(SUBSTRING_INDEX(i.ip_address,'.',3),'.0/24')
		WHERE {$ws}";

	$total_rows = empty( $v )
		? (int) $wpdb->get_var( "SELECT COUNT(*) {$base}" )
		: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base}", ...$v ) );

	$sql  = "SELECT e.*,i.ip_address,i.country,i.country_code,i.city,i.status AS ip_st,i.is_proxy,i.id AS ip_row_id,sn.is_banned AS subnet_banned {$base} ORDER BY e.created_at DESC LIMIT %d OFFSET %d";
	$rows = empty( $v )
		? $wpdb->get_results( $wpdb->prepare( $sql, $pp, $off ) )
		: $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $v, array( $pp, $off ) ) ) );

	$types = $wpdb->get_col( "SELECT DISTINCT event_type FROM " . stp_t( 'events' ) . " ORDER BY event_type" );
	?>
<div class="wrap stp-wrap">

<!-- HEADER -->
<div class="stp-hdr">
  <h1>🛡️ SecureTrack Pro</h1>
  <span class="stp-tagline">Behavioral Security Intelligence</span>
  <button class="stp-rbtn" onclick="stpRefresh()">↻ Refresh</button>
</div>

<!-- STAT CARDS -->
<div class="stp-grid">
  <?php
  $cards = array(
    array('blue',  $st['today'],               'Events Today',        'stp-ct'),
    array('red',   $st['red'],                 '🔴 Red Flags Today',   'stp-cr'),
    array('yellow',$st['yellow'],              '⚠️ Warnings Today',    'stp-cy'),
    array('purple',$st['fails24'],             'Failed Logins (24h)', ''),
    array('orange',$st['blocked'],             '<a href="?page=stp-ips&fs=blocked" style="color:inherit;text-decoration:none">Blocked IPs</a>', 'stp-cb'),
    array('teal',  $st['active'],              'Active Sessions',     'stp-ca'),
    array('green', $st['trusted'],             'Trusted IPs',         ''),
    array('gray',  number_format($st['total']),'Total Records',       ''),
  );
  foreach ( $cards as $cd ): ?>
    <div class="stp-card stp-card-<?php echo esc_attr( $cd[0] ); ?>" <?php echo $cd[3] ? 'id="'.esc_attr($cd[3]).'"' : ''; ?>>
      <span class="stp-n"><?php echo $cd[1]; ?></span>
      <span class="stp-l"><?php echo wp_kses_post( $cd[2] ); ?></span>
    </div>
  <?php endforeach; ?>
</div>

<!-- FILTERS -->
<div class="stp-bar">
  <form method="get" action="">
    <input type="hidden" name="page" value="stp">
    <select name="ft">
      <option value="">All Event Types</option>
      <?php foreach ( $types as $et ): ?>
        <option value="<?php echo esc_attr($et); ?>" <?php selected($ft,$et); ?>><?php echo esc_html(ucwords(str_replace('_',' ',$et))); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="ff">
      <option value="">All Flags</option>
      <?php foreach ( array( 'red'=>'🔴 Red', 'yellow'=>'🟡 Yellow', 'green'=>'🟢 Green', 'blocked'=>'⛔ Blocked' ) as $fv=>$fl ): ?>
        <option value="<?php echo $fv; ?>" <?php selected($ff,$fv); ?>><?php echo $fl; ?></option>
      <?php endforeach; ?>
    </select>
    <select name="fa"><option value="">Users + Visitors</option><option value="visitor" <?php selected($fa,'visitor'); ?>>Visitors only</option><option value="user" <?php selected($fa,'user'); ?>>Users only</option></select>
    <input type="text"  name="fu" value="<?php echo esc_attr($fu); ?>" placeholder="Username">
    <input type="text"  name="fi" value="<?php echo esc_attr($fi); ?>" placeholder="IP Address">
    <input type="date"  name="fd" value="<?php echo esc_attr($fd); ?>">
    <button type="submit" class="button button-primary">Filter</button>
    <a href="?page=stp" class="button">Clear</a>
  </form>
  <?php
  /* CSV export link — carries the active filters */
  $export_args = array_filter( array( 'action' => 'stp_export_csv', 'ff' => $ff, 'ft' => $ft ) );
  $export_url  = wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( $export_args ) ), 'stp_export_csv' );
  ?>
  <a href="<?php echo esc_url($export_url); ?>" class="button stp-export-btn" title="Download current view as CSV">⬇ Export CSV</a>
</div>

<!-- EVENTS TABLE -->
<div class="stp-tw">
<table class="stp-t">
<thead>
  <tr>
    <th>Flag</th><th>Time</th><th>Event / Object</th><th>User</th>
    <th>IP &amp; Location</th><th>Risk Score</th><th>Actions</th>
  </tr>
</thead>
<tbody>
<?php if ( empty( $rows ) ) { ?>
  <tr><td colspan="7" class="stp-emp">No events match the current filter.</td></tr>
<?php } else { ?>
<?php foreach ( $rows as $e ) {
  $fi_icon = array('green'=>'🟢','yellow'=>'🟡','red'=>'🔴','blocked'=>'⛔')[$e->flag_status] ?? '⚪';
  $rc      = (int)$e->risk_score >= 60 ? 'rr' : ((int)$e->risk_score >= 25 ? 'ry' : 'rg');
  $loc     = stp_location_label( $e );
  $subnet  = stp_subnet24_cidr( $e->ip_address ?? '' );
?>
<tr class="stp-r-<?php echo esc_attr($e->flag_status); ?> <?php echo $e->reviewed ? 'stp-rev' : ''; ?>"
    data-eid="<?php echo (int)$e->id; ?>">
  <td class="stp-fc"><?php echo $fi_icon; ?></td>
  <td class="stp-tc"><small><?php echo esc_html( date('M j H:i:s', strtotime($e->created_at)) ); ?></small></td>
  <td>
    <strong><?php echo esc_html( ucwords(str_replace('_',' ',$e->event_type)) ); ?></strong>
    <?php if ( $e->event_sub ): ?> <em class="stp-sub2">(<?php echo esc_html($e->event_sub); ?>)</em><?php endif; ?>
    <?php if ( $e->obj_title ): ?><br><small class="stp-ot"><?php echo esc_html( substr($e->obj_title,0,50) ); ?></small><?php endif; ?>
    <?php if ( $e->url ): ?><br><small class="stp-url"><?php echo esc_html( substr( parse_url($e->url,PHP_URL_PATH) ?? $e->url, 0, 60) ); ?></small><?php endif; ?>
  </td>
  <td><?php echo $e->username ? '<strong>'.esc_html($e->username).'</strong>' : '<em class="stp-vis">visitor</em>'; ?></td>
  <td>
    <?php echo stp_ip_link( $e->ip_address ?? '' ); ?>
    <?php if ( $e->is_proxy ): ?> <span class="stp-tag stp-tag-p">proxy</span><?php endif; ?>
    <br><small><?php echo esc_html( $loc ?: '—' ); ?></small>
    <br><small class="stp-ist stp-ist-<?php echo esc_attr($e->ip_st ?? 'unknown'); ?>"><?php echo esc_html( ucfirst($e->ip_st ?? 'unknown') ); ?></small>
  </td>
  <td>
    <span class="stp-sc <?php echo $rc; ?>"><?php echo (int)$e->risk_score; ?></span>
    <?php if ( $e->risk_reasons ): ?><br><small class="stp-rr"><?php echo esc_html( substr($e->risk_reasons,0,70) ); ?></small><?php endif; ?>
  </td>
  <td class="stp-ac">
    <button class="stp-b stp-bg" onclick="stpGreen(<?php echo (int)$e->id; ?>,'e')" title="Mark green / reviewed">✓</button>
    <button class="stp-b stp-bd" onclick="stpDel(<?php echo (int)$e->id; ?>)" title="Delete event">✕</button>
    <?php if ( $e->ip_row_id ): ?>
      <?php if ( ( $e->ip_st ?? '' ) === 'blocked' ): ?>
        <button class="stp-b stp-bb" onclick="stpUnblock(this,<?php echo (int)$e->ip_row_id; ?>)" title="Unblock this IP but keep monitoring">Unblock</button>
      <?php else: ?>
        <button class="stp-b stp-bb" onclick="stpBlock(this,<?php echo (int)$e->ip_row_id; ?>)" title="Block this IP">🚫</button>
      <?php endif; ?>
      <?php if ( $subnet ): ?>
        <?php if ( (int) $e->subnet_banned === 1 ): ?>
          <button class="stp-b stp-bb" onclick="stpUnbanSubnet(this,'<?php echo esc_js( $subnet ); ?>')" title="Unban this IPv4 /24 range">Unban /24</button>
        <?php else: ?>
          <button class="stp-b stp-bb" onclick="stpBlockRange(this,<?php echo (int)$e->ip_row_id; ?>)" title="Block nearby /24 range">⛔</button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ( ( $e->ip_st ?? '' ) === 'trusted' ): ?>
        <button class="stp-b stp-bt stp-disabled" disabled title="Already trusted">Trusted</button>
      <?php else: ?>
        <button class="stp-b stp-bt" onclick="stpTrust(this,<?php echo (int)$e->ip_row_id; ?>)" title="Trust this IP">✅</button>
      <?php endif; ?>
    <?php endif; ?>
  </td>
</tr>
<?php } ?>
<?php } ?>
</tbody>
</table>
</div>

<!-- PAGINATION -->
<?php
$total_pages = (int) ceil( $total_rows / $pp );
if ( $total_pages > 1 ):
?>
<div class="stp-pg">
  <?php for ( $i = 1; $i <= $total_pages; $i++ ):
    $qa = array_merge( (array)$_GET, array('paged'=>$i) );
    unset( $qa['_wpnonce'] );
  ?>
    <a href="<?php echo esc_url( add_query_arg( $qa, admin_url( 'admin.php' ) ) ); ?>" class="stp-pb <?php echo $i==$pn?'on':''; ?>"><?php echo $i; ?></a>
  <?php endfor; ?>
  <span class="stp-tot"><?php echo number_format($total_rows); ?> records total</span>
</div>
<?php endif; ?>

</div><!-- .stp-wrap -->
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: IP REPUTATION
// ════════════════════════════════════════════════════════════════

function stp_pg_ips() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;

	$fs = sanitize_text_field( $_GET['fs'] ?? '' );
	$fi = sanitize_text_field( $_GET['fi'] ?? '' );
	$sort = sanitize_key( $_GET['sort'] ?? 'risk' );
	if ( ! in_array( $sort, array( 'risk', 'recent', 'blocked_recent', 'hits', 'failures' ), true ) ) $sort = 'risk';
	$pn = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); $pp = 30; $off = ($pn-1)*$pp;
	$w = array( '1=1' ); $v = array();
	if ( $fs ) { $w[] = 'i.status=%s';           $v[] = $fs; }
	if ( $fi ) { $w[] = 'i.ip_address LIKE %s';  $v[] = '%' . $wpdb->esc_like( $fi ) . '%'; }
	$ws  = implode( ' AND ', $w );
	$base = "FROM " . stp_t('ips') . " i
		LEFT JOIN " . stp_t( 'subnets' ) . " sn ON sn.subnet=CONCAT(SUBSTRING_INDEX(i.ip_address,'.',3),'.0/24')
		WHERE {$ws}";
	$order_map = array(
		'risk'           => 'i.risk_score DESC,i.last_seen DESC',
		'recent'         => 'i.last_seen DESC,i.risk_score DESC',
		'blocked_recent' => "CASE WHEN i.blocked_at IS NULL THEN 1 ELSE 0 END ASC,i.blocked_at DESC,i.last_seen DESC",
		'hits'           => 'i.total_hits DESC,i.last_seen DESC',
		'failures'       => 'i.failed_logins DESC,i.last_seen DESC',
	);
	$sql = "SELECT i.*,sn.is_banned AS subnet_banned {$base} ORDER BY {$order_map[$sort]} LIMIT %d OFFSET %d";

	$ips = empty( $v )
		? $wpdb->get_results( $wpdb->prepare( $sql, $pp, $off ) )
		: $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $v, array($pp, $off) ) ) );
	$total = empty( $v )
		? (int) $wpdb->get_var( "SELECT COUNT(*) {$base}" )
		: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base}", ...$v ) );
	?>
<div class="wrap stp-wrap">
<h1>🌐 IP Reputation Database</h1>
<div class="stp-bar"><form method="get">
  <input type="hidden" name="page" value="stp-ips">
  <select name="fs">
    <option value="">All Status</option>
    <?php foreach ( array('blocked'=>'⛔ Blocked','trusted'=>'✅ Trusted','monitor'=>'👁 Monitor','unknown'=>'❓ Unknown') as $sv=>$sl ): ?>
      <option value="<?php echo $sv; ?>" <?php selected($fs,$sv); ?>><?php echo $sl; ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="fi" value="<?php echo esc_attr($fi); ?>" placeholder="IP address">
  <select name="sort">
    <?php foreach ( array( 'risk' => 'Highest risk', 'recent' => 'Recently seen', 'blocked_recent' => 'Recently blocked', 'hits' => 'Most hits', 'failures' => 'Most failed logins' ) as $sv => $sl ): ?>
      <option value="<?php echo esc_attr( $sv ); ?>" <?php selected( $sort, $sv ); ?>><?php echo esc_html( $sl ); ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="button button-primary">Filter</button>
  <a href="<?php echo esc_url( admin_url( 'admin.php?page=stp-ips' ) ); ?>" class="button">Clear</a>
</form></div>

<div class="stp-tw"><table class="stp-t">
<thead><tr>
  <th>Status</th><th>IP Address</th><th>Location</th><th>ISP / Org</th>
  <th>Risk</th><th>Fail Logins</th><th>Total Hits</th><th>First / Last Seen</th><th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ( $ips as $ip ):
  $si = array('blocked'=>'⛔','trusted'=>'✅','monitor'=>'👁','unknown'=>'❓')[$ip->status] ?? '❓';
  $rc = (int)$ip->risk_score>=60 ? 'rr' : ((int)$ip->risk_score>=25 ? 'ry' : 'rg');
  $loc = stp_location_label( $ip );
  $subnet = stp_subnet24_cidr( $ip->ip_address );
  $eff = stp_effective_block_label( $ip );
?>
<tr class="stp-ip-<?php echo esc_attr($ip->status); ?>">
  <td><?php echo ! empty( $eff['blocked'] ) ? '⛔' : $si; ?> <small><?php echo esc_html( $eff['label'] ); ?></small><?php echo ! empty( $eff['blocked'] ) && $eff['source'] !== 'ip_block' ? '<br><small class="stp-rr">IP status: ' . esc_html( ucfirst( $ip->status ) ) . '</small>' : ''; ?></td>
  <td>
    <?php echo stp_ip_link( $ip->ip_address ); ?>
    <?php echo $ip->is_proxy   ? ' <span class="stp-tag stp-tag-p">proxy</span>'   : ''; ?>
    <?php echo $ip->is_hosting ? ' <span class="stp-tag stp-tag-h">VPS/DC</span>'  : ''; ?>
  </td>
  <td><?php echo esc_html( $loc ?: ($ip->geo_fetched ? '—' : 'Fetching…') ); ?></td>
  <td><small><?php echo esc_html( substr($ip->isp ?? '',0,40) ); ?></small></td>
  <td><span class="stp-sc <?php echo $rc; ?>"><?php echo (int)$ip->risk_score; ?></span></td>
  <td><?php echo (int)$ip->failed_logins; ?></td>
  <td><?php echo number_format( (int)$ip->total_hits ); ?></td>
  <td>
    <small><?php echo esc_html( date('M j Y', strtotime($ip->first_seen)) ); ?><br>
    <?php echo esc_html( date('M j H:i', strtotime($ip->last_seen)) ); ?>
    <?php if ( ! empty( $ip->blocked_at ) ): ?><br><span class="stp-rr">Blocked <?php echo esc_html( date('M j H:i', strtotime($ip->blocked_at)) ); ?></span><?php endif; ?></small>
  </td>
  <td class="stp-ac">
    <?php if ( $ip->status === 'blocked' ): ?>
      <button class="stp-b stp-bb" onclick="stpUnblock(this,<?php echo (int)$ip->id; ?>)" title="Unblock this IP but keep monitoring">Unblock</button>
    <?php elseif ( ! empty( $eff['blocked'] ) ): ?>
      <button class="stp-b stp-bb stp-disabled" disabled title="This IP is already blocked by <?php echo esc_attr( $eff['label'] ); ?>"><?php echo esc_html( $eff['label'] ); ?></button>
    <?php else: ?>
      <button class="stp-b stp-bb" onclick="stpBlock(this,<?php echo (int)$ip->id; ?>)" title="Block">🚫</button>
    <?php endif; ?>
    <?php if ( $subnet ): ?>
      <?php if ( (int) $ip->subnet_banned === 1 ): ?>
        <button class="stp-b stp-bb" onclick="stpUnbanSubnet(this,'<?php echo esc_js( $subnet ); ?>')" title="Unban this IPv4 /24 range">Unban /24</button>
      <?php else: ?>
        <button class="stp-b stp-bb" onclick="stpBlockRange(this,<?php echo (int)$ip->id; ?>)" title="Block nearby /24 range">⛔</button>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ( $ip->status === 'trusted' ): ?>
      <button class="stp-b stp-bt stp-disabled" disabled title="Already trusted">Trusted</button>
    <?php else: ?>
      <button class="stp-b stp-bt" onclick="stpTrust(this,<?php echo (int)$ip->id; ?>)" title="Trust">✅</button>
    <?php endif; ?>
    <button class="stp-b stp-bg" onclick="stpGreen(<?php echo (int)$ip->id; ?>,'ip')" title="Green all events">🟢</button>
    <button class="stp-b stp-bd" onclick="stpDelIp(<?php echo (int)$ip->id; ?>)" title="Delete IP + all events">🗑</button>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php
	$total_pages = max( 1, (int) ceil( $total / $pp ) );
	$page_args = array_filter( array( 'page' => 'stp-ips', 'fs' => $fs, 'fi' => $fi, 'sort' => $sort ), 'strlen' );
?>
<div class="stp-pg">
  <span class="stp-tot"><?php echo number_format($total); ?> IPs tracked</span>
  <?php if ( $total_pages > 1 ): ?>
    <?php if ( $pn > 1 ): ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( array_merge( $page_args, array( 'paged' => $pn - 1 ) ), admin_url( 'admin.php' ) ) ); ?>">Previous</a><?php endif; ?>
    <span class="stp-tot">Page <?php echo number_format_i18n( $pn ); ?> of <?php echo number_format_i18n( $total_pages ); ?></span>
    <?php if ( $pn < $total_pages ): ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( array_merge( $page_args, array( 'paged' => $pn + 1 ) ), admin_url( 'admin.php' ) ) ); ?>">Next</a><?php endif; ?>
  <?php endif; ?>
</div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: ALERTS
// ════════════════════════════════════════════════════════════════

function stp_pg_alerts() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;
	$filter = sanitize_text_field( $_GET['filter'] ?? 'open' );
	$bf = sanitize_key( $_GET['bf'] ?? 'all' );
	if ( ! in_array( $bf, array( 'all', 'blocked', 'not_blocked' ), true ) ) $bf = 'all';
	$where = $filter === 'resolved' ? 'a.is_resolved=1' : ( $filter === 'all' ? '1=1' : 'a.is_resolved=0' );
	if ( $bf === 'blocked' ) {
		$where .= " AND (i.status='blocked' OR COALESCE(s.is_banned,0)=1)";
	} elseif ( $bf === 'not_blocked' ) {
		$where .= " AND COALESCE(i.status,'unknown')<>'blocked' AND COALESCE(s.is_banned,0)=0";
	}
	$pn = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$pp = 100;
	$off = ( $pn - 1 ) * $pp;
	$join = " FROM " . stp_t( 'alerts' ) . " a
		 LEFT JOIN " . stp_t( 'ips' ) . " i ON i.ip_address=a.ip_address
		 LEFT JOIN " . stp_t( 'subnets' ) . " s ON s.subnet=a.subnet_24
		 WHERE {$where}";
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT a.*, i.status AS ip_status, i.status AS ip_st, i.country_code, s.is_banned AS subnet_banned
		 {$join}
		 ORDER BY a.alert_time DESC LIMIT %d OFFSET %d",
		$pp, $off
	) );
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$join}" );
	$open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'alerts' ) . " WHERE is_resolved=0" );
	$seen_cursor = stp_seen_cursor( 'alerts' );
	$visible_ids = wp_list_pluck( (array) $rows, 'id' );
	$max_visible_id = $visible_ids ? max( array_map( 'intval', $visible_ids ) ) : 0;
	$new_visible = 0;
	foreach ( (array) $rows as $seen_row ) {
		if ( (int) $seen_row->id > $seen_cursor ) $new_visible++;
	}
	?>
<div class="wrap stp-wrap">
<h1>Security Alerts</h1>
<?php if ( $new_visible > 0 ): ?>
  <div class="stp-new-summary"><strong><?php echo number_format_i18n( $new_visible ); ?> new alert<?php echo $new_visible === 1 ? '' : 's'; ?></strong> since you last opened this view. They will be marked seen after this page load.</div>
<?php endif; ?>
<div class="stp-bar">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="page" value="stp-alerts">
    <select name="filter">
      <option value="open" <?php selected( $filter, 'open' ); ?>>Open (<?php echo number_format( $open ); ?>)</option>
      <option value="all" <?php selected( $filter, 'all' ); ?>>All alerts</option>
      <option value="resolved" <?php selected( $filter, 'resolved' ); ?>>Resolved</option>
    </select>
    <select name="bf">
      <option value="all" <?php selected( $bf, 'all' ); ?>>All block states</option>
      <option value="blocked" <?php selected( $bf, 'blocked' ); ?>>Currently blocked</option>
      <option value="not_blocked" <?php selected( $bf, 'not_blocked' ); ?>>Not currently blocked</option>
    </select>
    <button class="button button-primary">Filter</button>
    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=stp-alerts' ) ); ?>">Clear</a>
  </form>
</div>
<div class="stp-tw"><table class="stp-t">
<thead><tr><th>Time</th><th>Severity</th><th>Alert</th><th>Target</th><th>User</th><th>Actions</th></tr></thead>
<tbody>
<?php if ( ! $rows ): ?>
  <tr><td colspan="6">No alerts in this view.</td></tr>
<?php endif; ?>
<?php $stp_alert_new_divider = false; $stp_alert_old_divider = false; ?>
<?php foreach ( $rows as $a ):
	$sev_class = $a->severity === 'critical' ? 'rr' : ( $a->severity === 'high' ? 'ry' : 'rg' );
	$eff = stp_effective_block_label( $a );
	$is_new_alert = (int) $a->id > $seen_cursor;
	if ( $is_new_alert && ! $stp_alert_new_divider ) {
		$stp_alert_new_divider = true;
		echo '<tr class="stp-new-divider"><td colspan="6">New alerts since your last visit</td></tr>';
	} elseif ( ! $is_new_alert && $stp_alert_new_divider && ! $stp_alert_old_divider ) {
		$stp_alert_old_divider = true;
		echo '<tr class="stp-old-divider"><td colspan="6">Seen earlier</td></tr>';
	}
?>
<tr data-alert="<?php echo (int) $a->id; ?>" class="<?php echo $a->is_resolved ? 'stp-r-green' : 'stp-r-red'; ?> <?php echo $is_new_alert ? 'stp-new-row' : ''; ?>">
  <td><small><?php echo esc_html( $a->alert_time ? date( 'M j H:i', strtotime( $a->alert_time ) ) : '—' ); ?></small></td>
  <td><span class="stp-sc <?php echo esc_attr( $sev_class ); ?>"><?php echo esc_html( strtoupper( $a->severity ) ); ?></span><br><small><?php echo esc_html( $a->chain_type ); ?></small></td>
  <td><strong><?php echo esc_html( $a->title ); ?></strong><br><small><?php echo esc_html( wp_strip_all_tags( $a->description ) ); ?></small></td>
  <td>
    <?php if ( $a->ip_address ): ?><?php echo stp_ip_link( $a->ip_address ); ?><br><?php endif; ?>
    <?php if ( $a->subnet_24 ): ?><code><?php echo esc_html( $a->subnet_24 ); ?></code><?php endif; ?>
    <?php if ( $a->ip_address ): ?><br><small class="stp-ist stp-ist-<?php echo esc_attr( $eff['status'] ?? 'unknown' ); ?>"><?php echo esc_html( $eff['label'] ); ?></small><?php endif; ?>
  </td>
  <td><?php echo $a->username ? esc_html( $a->username ) : '—'; ?></td>
  <td class="stp-ac">
    <?php if ( ! $a->is_resolved ): ?>
      <button class="button button-small" onclick="stpResolveAlert(<?php echo (int) $a->id; ?>)">Resolve</button>
    <?php else: ?>
      <small>Resolved <?php echo esc_html( $a->resolved_at ? date( 'M j H:i', strtotime( $a->resolved_at ) ) : '' ); ?></small>
    <?php endif; ?>
    <?php if ( $a->ip_address ): ?>
      <?php if ( ! empty( $eff['blocked'] ) && ( $eff['source'] ?? '' ) === 'ip_block' ): ?>
        <button class="button button-small" onclick="stpUnblockIpByAddress(this,'<?php echo esc_js( $a->ip_address ); ?>')">Unblock IP</button>
      <?php elseif ( ! empty( $eff['blocked'] ) ): ?>
        <button class="button button-small stp-disabled" disabled><?php echo esc_html( $eff['label'] ); ?></button>
      <?php elseif ( ( $eff['status'] ?? '' ) === 'trusted' ): ?>
        <button class="button button-small stp-disabled" disabled>Trusted</button>
      <?php else: ?>
        <button class="button button-small" onclick="stpBlockIpByAddress(this,'<?php echo esc_js( $a->ip_address ); ?>')">Block IP</button>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ( $a->subnet_24 ): ?>
      <?php if ( (int) $a->subnet_banned === 1 ): ?>
        <button class="button button-small" onclick="stpUnbanSubnet(this,'<?php echo esc_js( $a->subnet_24 ); ?>')">Unban /24</button>
      <?php else: ?>
        <button class="button button-small" onclick="stpBanSubnet(this,'<?php echo esc_js( $a->subnet_24 ); ?>')">Ban /24</button>
      <?php endif; ?>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php
	$total_pages = max( 1, (int) ceil( $total / $pp ) );
	$page_args = array( 'page' => 'stp-alerts', 'filter' => $filter, 'bf' => $bf );
?>
<div class="stp-pg">
  <span class="stp-tot"><?php echo number_format_i18n( $total ); ?> alert(s) in this view</span>
  <?php if ( $total_pages > 1 ): ?>
    <?php if ( $pn > 1 ): ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( array_merge( $page_args, array( 'paged' => $pn - 1 ) ), admin_url( 'admin.php' ) ) ); ?>">Previous</a><?php endif; ?>
    <span class="stp-tot">Page <?php echo number_format_i18n( $pn ); ?> of <?php echo number_format_i18n( $total_pages ); ?></span>
    <?php if ( $pn < $total_pages ): ?><a class="button button-small" href="<?php echo esc_url( add_query_arg( array_merge( $page_args, array( 'paged' => $pn + 1 ) ), admin_url( 'admin.php' ) ) ); ?>">Next</a><?php endif; ?>
  <?php endif; ?>
</div>
</div>
<?php
	if ( $max_visible_id ) stp_mark_seen_cursor( 'alerts', $max_visible_id );
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: SUCCESSFUL PROTECTIONS
// ════════════════════════════════════════════════════════════════

function stp_protection_label( $event_type, $event_sub, $extra = array() ) {
	if ( $event_type === 'protection_block' && ! empty( $extra['would_waf_block'] ) ) {
		if ( $event_sub === 'subnet_ban' ) return 'Banned /24 denied malicious payload';
		if ( $event_sub === 'country_blocklist' ) return 'Blocked country denied malicious payload';
		return 'Blocked IP denied malicious payload';
	}
	$key = $event_type . ':' . $event_sub;
	$map = array(
		'protection_block:ip_block'                       => 'Blocked IP denied',
		'protection_block:subnet_ban'                     => 'Banned /24 denied',
		'protection_block:country_blocklist'              => 'Blocked country denied',
		'protection_block:login_country_policy'           => 'Login country policy denied',
		'protection_block:endpoint_rate_limit'            => 'Endpoint flood rate-limited',
		'waf_block:honeypot_hit'                          => 'Honeypot caught bot',
		'waf_block:attack_graph_preemptive_limit'         => 'Preemptive attack-graph limit',
		'rest_abuse:user_enumeration_blocked'             => 'REST user enumeration blocked',
		'page_view:author_archive_blocked'                => 'Author enumeration blocked',
	);
	if ( isset( $map[ $key ] ) ) return $map[ $key ];
	if ( $event_type === 'waf_block' ) return 'Adaptive WAF blocked payload';
	if ( $event_type === 'rest_abuse' ) return 'REST abuse blocked';
	return ucwords( str_replace( '_', ' ', $event_type ) );
}

function stp_protection_response_label( $row, $extra ) {
	if ( ! empty( $extra['response_shown'] ) ) return $extra['response_shown'];
	if ( $row->event_type === 'waf_block' && $row->event_sub === 'attack_graph_preemptive_limit' ) return '429 Temporarily rate limited';
	if ( $row->event_type === 'waf_block' ) return '403 Forbidden';
	if ( $row->event_type === 'rest_abuse' ) return '403 User enumeration is blocked';
	if ( $row->event_sub === 'login_country_policy' ) return '403 Login denied by allowed-country policy';
	if ( $row->event_sub === 'author_archive_blocked' ) return '301 Redirected to home';
	return 'Handled automatically';
}

function stp_pg_protections() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;
	$pf = sanitize_key( $_GET['pf'] ?? 'all' );
	if ( ! in_array( $pf, array( 'all', 'blocked', 'not_blocked' ), true ) ) $pf = 'all';
	$since = date( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
	$where = "(e.event_type IN ('protection_block','waf_block','rest_abuse') OR e.event_sub IN ('author_archive_blocked','user_enumeration_blocked','honeypot_hit','attack_graph_preemptive_limit','login_country_policy','endpoint_rate_limit'))";
	$rows = $wpdb->get_results(
		"SELECT e.*,i.ip_address,i.country,i.country_code,i.city,i.status AS ip_st,i.is_proxy,i.id AS ip_row_id,
		        sn.is_banned AS subnet_banned
		 FROM " . stp_t( 'events' ) . " e
		 LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 LEFT JOIN " . stp_t( 'subnets' ) . " sn ON sn.subnet=CONCAT(SUBSTRING_INDEX(i.ip_address,'.',3),'.0/24')
		 WHERE {$where}
		 ORDER BY e.created_at DESC LIMIT 300"
	);
	if ( $pf !== 'all' ) {
		$rows = array_values( array_filter( (array) $rows, function( $row ) use ( $pf ) {
			$eff = stp_effective_block_label( $row );
			return $pf === 'blocked' ? ! empty( $eff['blocked'] ) : empty( $eff['blocked'] );
		} ) );
	}
	$seen_cursor = stp_seen_cursor( 'protections' );
	$visible_ids = wp_list_pluck( (array) $rows, 'id' );
	$max_visible_id = $visible_ids ? max( array_map( 'intval', $visible_ids ) ) : 0;
	$new_visible = 0;
	foreach ( (array) $rows as $seen_row ) {
		if ( (int) $seen_row->id > $seen_cursor ) $new_visible++;
	}
	$counts = array(
		'blocked' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e WHERE e.event_type='protection_block' AND e.created_at>%s", $since ) ),
		'waf'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e WHERE e.event_type='waf_block' AND e.created_at>%s", $since ) ),
		'rest'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e WHERE e.event_type='rest_abuse' AND e.created_at>%s", $since ) ),
		'total'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e WHERE {$where} AND e.created_at>%s", $since ) ),
	);
	?>
<div class="wrap stp-wrap">
<h1>Successful Protections</h1>
<p class="stp-desc">A positive security ledger: requests SecureTrack already handled, blocked, redirected, or rate-limited for you.</p>
<?php if ( $new_visible > 0 ): ?>
  <div class="stp-new-summary stp-new-summary-green"><strong><?php echo number_format_i18n( $new_visible ); ?> new protection<?php echo $new_visible === 1 ? '' : 's'; ?></strong> since you last opened this view. They will be marked seen after this page load.</div>
<?php endif; ?>
<div class="stp-grid">
  <div class="stp-card stp-card-green"><span class="stp-n"><?php echo number_format( $counts['total'] ); ?></span><span class="stp-l">Protections 24h</span></div>
  <div class="stp-card stp-card-orange"><span class="stp-n"><?php echo number_format( $counts['blocked'] ); ?></span><span class="stp-l">Policy / IP denies</span></div>
  <div class="stp-card stp-card-red"><span class="stp-n"><?php echo number_format( $counts['waf'] ); ?></span><span class="stp-l">WAF blocks</span></div>
  <div class="stp-card stp-card-blue"><span class="stp-n"><?php echo number_format( $counts['rest'] ); ?></span><span class="stp-l">REST blocks</span></div>
</div>
<div class="stp-bar">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="page" value="stp-protections">
    <select name="pf">
      <option value="all" <?php selected( $pf, 'all' ); ?>>All protections</option>
      <option value="blocked" <?php selected( $pf, 'blocked' ); ?>>Currently blocked</option>
      <option value="not_blocked" <?php selected( $pf, 'not_blocked' ); ?>>Not currently blocked</option>
    </select>
    <button class="button button-primary">Filter</button>
    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=stp-protections' ) ); ?>">Clear</a>
  </form>
</div>
<div class="stp-tw"><table class="stp-t">
<thead><tr><th>Time</th><th>Protection</th><th>Who / Where</th><th>Target</th><th>Shown / Outcome</th><th>Repeat Signal</th><th>Actions</th></tr></thead>
<tbody>
<?php if ( ! $rows ): ?>
  <tr><td colspan="7">No successful protection events yet.</td></tr>
<?php endif; ?>
<?php $stp_protection_new_divider = false; $stp_protection_old_divider = false; ?>
<?php foreach ( $rows as $r ):
	$extra = json_decode( (string) $r->extra, true );
	$extra = is_array( $extra ) ? $extra : array();
	$subnet = stp_subnet24_cidr( $r->ip_address ?? '' );
	$loc = stp_location_label( $r );
	$path = $r->url ? ( wp_parse_url( $r->url, PHP_URL_PATH ) ?: $r->url ) : '—';
	$eff = stp_effective_block_label( $r );
	$repeat = $r->ip_address ? (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM " . stp_t( 'events' ) . " e JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id WHERE i.ip_address=%s AND e.created_at>%s AND {$where}",
		$r->ip_address, $since
	) ) : 0;
	$is_new_protection = (int) $r->id > $seen_cursor;
	if ( $is_new_protection && ! $stp_protection_new_divider ) {
		$stp_protection_new_divider = true;
		echo '<tr class="stp-new-divider stp-new-divider-green"><td colspan="7">New protections since your last visit</td></tr>';
	} elseif ( ! $is_new_protection && $stp_protection_new_divider && ! $stp_protection_old_divider ) {
		$stp_protection_old_divider = true;
		echo '<tr class="stp-old-divider"><td colspan="7">Seen earlier</td></tr>';
	}
?>
<tr class="stp-r-green <?php echo $is_new_protection ? 'stp-new-row' : ''; ?>">
  <td><small><?php echo esc_html( date( 'M j H:i:s', strtotime( $r->created_at ) ) ); ?></small></td>
  <td><strong><?php echo esc_html( stp_protection_label( $r->event_type, $r->event_sub, $extra ) ); ?></strong><br><small><?php echo esc_html( $r->event_type . ( $r->event_sub ? ':' . $r->event_sub : '' ) ); ?></small></td>
  <td>
    <?php if ( $r->ip_address ): ?><?php echo stp_ip_link( $r->ip_address ); ?><?php endif; ?>
    <?php if ( $r->is_proxy ): ?> <span class="stp-tag stp-tag-p">proxy</span><?php endif; ?>
    <br><small><?php echo esc_html( $loc ?: '—' ); ?></small>
    <br><small class="stp-ist stp-ist-<?php echo esc_attr( $eff['status'] ?? 'unknown' ); ?>"><?php echo esc_html( $eff['label'] ); ?></small>
  </td>
  <td><small class="stp-url"><?php echo esc_html( substr( $path, 0, 100 ) ); ?></small></td>
  <td><small><?php echo esc_html( stp_protection_response_label( $r, $extra ) ); ?><?php echo ! empty( $extra['payload_flags'] ) ? '<br>Payload: ' . esc_html( implode( ', ', (array) $extra['payload_flags'] ) ) : ''; ?></small></td>
  <td><?php echo $repeat > 1 ? '<strong>' . number_format( $repeat ) . '</strong> attempts in 24h' : 'First seen in 24h'; ?></td>
  <td class="stp-ac">
    <?php if ( $r->ip_row_id ): ?>
      <?php if ( ! empty( $eff['blocked'] ) && ( $eff['source'] ?? '' ) === 'ip_block' ): ?>
        <button class="stp-b stp-bb" onclick="stpUnblock(this,<?php echo (int) $r->ip_row_id; ?>)">Unblock</button>
      <?php elseif ( ! empty( $eff['blocked'] ) ): ?>
        <button class="stp-b stp-disabled" disabled><?php echo esc_html( $eff['label'] ); ?></button>
      <?php else: ?>
        <button class="stp-b stp-bb" onclick="stpBlock(this,<?php echo (int) $r->ip_row_id; ?>)">Block</button>
      <?php endif; ?>
      <?php if ( $subnet ): ?>
        <?php if ( (int) $r->subnet_banned === 1 ): ?>
          <button class="stp-b stp-bb" onclick="stpUnbanSubnet(this,'<?php echo esc_js( $subnet ); ?>')">Unban /24</button>
        <?php else: ?>
          <button class="stp-b stp-bb" onclick="stpBlockRange(this,<?php echo (int) $r->ip_row_id; ?>)">Ban /24</button>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php
	if ( $max_visible_id ) stp_mark_seen_cursor( 'protections', $max_visible_id );
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: SITE BRAIN
// ════════════════════════════════════════════════════════════════

function stp_pg_brain() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;
	$cfg = stp_cfg();
	$last_training = (array) get_option( 'stp_brain_last_training', array() );
	$ai_status = stp_ai_status();
	if ( ! stp_table_exists( 'brain' ) || ! stp_table_exists( 'ai_queue' ) ) {
		echo '<div class="wrap stp-wrap"><h1>Site Brain</h1><p>v2 tables are not ready yet. Run Repair Database from Settings.</p></div>';
		return;
	}
	if ( isset( $_POST['stp_do'] ) && $_POST['stp_do'] === 'trainbrain' && check_admin_referer( 'stp_do' ) ) {
		$res = stp_brain_train_from_history( 50000 );
		echo ! empty( $res['error'] )
			? '<div class="notice notice-error is-dismissible"><p>Site Brain training did not replace the current model: ' . esc_html( $res['error'] ) . '</p></div>'
			: '<div class="notice notice-success is-dismissible"><p>Site Brain trained from existing events. Processed ' . number_format_i18n( (int) $res['processed'] ) . ' historical events and built ' . number_format_i18n( (int) $res['features'] ) . ' learned features. Historical SecureTrack data was preserved.</p></div>';
		$last_training = (array) get_option( 'stp_brain_last_training', array() );
	} elseif ( isset( $_POST['stp_do'] ) && $_POST['stp_do'] === 'processai' && check_admin_referer( 'stp_do' ) ) {
		$res = stp_ai_process_pending_queue( 25 );
		$msg = 'AI queue processed. Reviewed ' . number_format_i18n( (int) $res['reviewed'] ) . ' item(s), errors ' . number_format_i18n( (int) $res['errors'] ) . '.';
		if ( ! empty( $res['paused'] ) ) $msg .= ' Processing paused because the provider reported quota/rate limit.';
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
	$brain = $wpdb->get_row( "SELECT COUNT(*) features,COALESCE(SUM(good_count),0) good,COALESCE(SUM(risk_count),0) risk,COALESCE(AVG(confidence),0) avg_conf FROM " . stp_t( 'brain' ), ARRAY_A );
	$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'ai_queue' ) . " WHERE status='pending'" );
	$reviewed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'ai_queue' ) . " WHERE status='reviewed'" );
	$ai_errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'ai_queue' ) . " WHERE status='error'" );
	$local_only = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'ai_queue' ) . " WHERE status='local_only'" );
	$today = $wpdb->get_row( "SELECT COUNT(*) total,SUM(CASE WHEN risk_score<25 THEN 1 ELSE 0 END) clear_local,SUM(CASE WHEN risk_score BETWEEN 30 AND 70 THEN 1 ELSE 0 END) uncertain,SUM(CASE WHEN risk_score>=75 THEN 1 ELSE 0 END) decisive FROM " . stp_t( 'events' ) . " WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)", ARRAY_A );
	$total_today = max( 1, (int) ( $today['total'] ?? 0 ) );
	$handled_pct = round( ( (int) ( $today['clear_local'] ?? 0 ) + (int) ( $today['decisive'] ?? 0 ) ) / $total_today * 100 );
	$feature_count = (int) ( $brain['features'] ?? 0 );
	$avg_conf = (int) round( (float) ( $brain['avg_conf'] ?? 0 ) );
	$maturity = $feature_count < 25 ? 'Learning' : ( $avg_conf < 35 ? 'Established' : 'Mature' );
	$risky = $wpdb->get_results( "SELECT * FROM " . stp_t( 'brain' ) . " WHERE risk_count>0 ORDER BY risk_count DESC,confidence DESC,last_seen DESC LIMIT 12" );
	$normal = $wpdb->get_results( "SELECT * FROM " . stp_t( 'brain' ) . " WHERE good_count>=10 AND risk_count=0 ORDER BY good_count DESC,last_seen DESC LIMIT 12" );
	$queue = $wpdb->get_results( "SELECT q.*,e.event_type,e.event_sub,e.url,i.ip_address FROM " . stp_t( 'ai_queue' ) . " q LEFT JOIN " . stp_t( 'events' ) . " e ON e.id=q.event_id LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id ORDER BY q.id DESC LIMIT 40" );
	$ai_outcomes = $wpdb->get_row( "SELECT COUNT(*) total,
		SUM(CASE WHEN ai_label='clean' THEN 1 ELSE 0 END) clean,
		SUM(CASE WHEN ai_label='protected' THEN 1 ELSE 0 END) protected,
		SUM(CASE WHEN ai_label='suspicious' THEN 1 ELSE 0 END) suspicious,
		SUM(CASE WHEN ai_label='critical' THEN 1 ELSE 0 END) critical,
		COALESCE(AVG(ai_score),0) avg_score
		FROM " . stp_t( 'ai_queue' ) . " WHERE status='reviewed' AND reviewed_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)", ARRAY_A );
	$ai_alerts_24h = stp_table_exists( 'alerts' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . stp_t( 'alerts' ) . " WHERE chain_type='ai_critical_review' AND alert_time>=DATE_SUB(NOW(),INTERVAL 24 HOUR)" ) : 0;
	?>
<div class="wrap stp-wrap">
<h1>Site Brain</h1>
<p class="stp-desc">SecureTrack v2 learns normal and risky patterns locally first. Optional AI review only receives compact uncertain-event summaries when a provider key is configured.</p>
<div class="stp-bar">
  <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=stp-brain' ) ); ?>" onsubmit="return confirm('Rebuild the Site Brain model from existing SecureTrack events? This preserves all events, alerts, protections, IPs, sessions, and subnets.');">
    <?php wp_nonce_field( 'stp_do' ); ?>
    <input type="hidden" name="page" value="stp-brain">
    <input type="hidden" name="stp_do" value="trainbrain">
    <button class="button button-primary">Train Site Brain from Existing Events</button>
    <span class="stp-desc">Last training: <?php echo ! empty( $last_training['time'] ) ? esc_html( $last_training['time'] . ' / ' . number_format_i18n( (int) ( $last_training['processed'] ?? 0 ) ) . ' events' ) : 'never'; ?></span>
  </form>
</div>
<div class="stp-grid">
  <div class="stp-card stp-card-blue"><span class="stp-n"><?php echo esc_html( $maturity ); ?></span><span class="stp-l">Training Status</span></div>
  <div class="stp-card stp-card-green"><span class="stp-n"><?php echo number_format( $handled_pct ); ?>%</span><span class="stp-l">Local Decisions 24h</span></div>
  <div class="stp-card stp-card-purple"><span class="stp-n"><?php echo number_format( $feature_count ); ?></span><span class="stp-l">Learned Features</span></div>
  <div class="stp-card stp-card-orange"><span class="stp-n"><?php echo number_format( $pending ); ?></span><span class="stp-l">AI Pending</span></div>
  <div class="stp-card stp-card-green"><span class="stp-n"><?php echo number_format( $reviewed ); ?></span><span class="stp-l">AI Reviewed</span></div>
  <div class="stp-card stp-card-yellow"><span class="stp-n"><?php echo number_format( $ai_errors ); ?></span><span class="stp-l">AI Errors</span></div>
  <div class="stp-card stp-card-teal"><span class="stp-n"><?php echo esc_html( strtoupper( $cfg['v2_ai_provider'] ?? 'none' ) ); ?></span><span class="stp-l">Provider / <?php echo esc_html( $cfg['v2_ai_mode'] === 'always' ? 'Always On' : 'Batch' ); ?></span></div>
  <div class="stp-card stp-card-red"><span class="stp-n"><?php echo number_format( $local_only ); ?></span><span class="stp-l">Local-Only Uncertain</span></div>
</div>
<div class="stp-grid">
  <div class="stp-card stp-card-green"><span class="stp-n"><?php echo number_format( (int) ( $ai_outcomes['total'] ?? 0 ) ); ?></span><span class="stp-l">AI Reviews 24h</span></div>
  <div class="stp-card stp-card-blue"><span class="stp-n"><?php echo number_format( (int) ( $ai_outcomes['clean'] ?? 0 ) ); ?></span><span class="stp-l">AI Clean</span></div>
  <div class="stp-card stp-card-green"><span class="stp-n"><?php echo number_format( (int) ( $ai_outcomes['protected'] ?? 0 ) ); ?></span><span class="stp-l">AI Protected</span></div>
  <div class="stp-card stp-card-yellow"><span class="stp-n"><?php echo number_format( (int) ( $ai_outcomes['suspicious'] ?? 0 ) ); ?></span><span class="stp-l">AI Suspicious</span></div>
  <div class="stp-card stp-card-red"><span class="stp-n"><?php echo number_format( (int) ( $ai_outcomes['critical'] ?? 0 ) ); ?></span><span class="stp-l">AI Critical</span></div>
  <div class="stp-card stp-card-purple"><span class="stp-n"><?php echo number_format( (int) round( (float) ( $ai_outcomes['avg_score'] ?? 0 ) ) ); ?></span><span class="stp-l">Avg AI Score 24h</span></div>
  <div class="stp-card stp-card-orange"><span class="stp-n"><?php echo number_format( $ai_alerts_24h ); ?></span><span class="stp-l">AI Alerts Raised 24h</span></div>
</div>
<div class="stp-bar">
  <strong>AI Connection:</strong>
  <?php if ( ! empty( $ai_status['connected'] ) && ( $ai_status['provider'] ?? '' ) === ( $cfg['v2_ai_provider'] ?? '' ) ): ?>
    <span style="color:#059669;font-weight:700">Connected</span>
  <?php elseif ( empty( $cfg['v2_ai_key'] ) || ( $cfg['v2_ai_provider'] ?? 'none' ) === 'none' ): ?>
    <span style="color:#64748b;font-weight:700">Not configured</span>
  <?php else: ?>
    <span style="color:#d97706;font-weight:700">Key stored, not verified</span>
  <?php endif; ?>
  <small><?php echo ! empty( $ai_status['message'] ) ? esc_html( ' - ' . $ai_status['message'] . ' / ' . ( $ai_status['updated_at'] ?? '' ) ) : ''; ?></small>
  <a href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=testai', 'stp_do' ) ); ?>" class="button button-small">Test AI Connection</a>
  <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=stp-brain' ) ); ?>" style="display:inline-block;margin-left:6px">
    <?php wp_nonce_field( 'stp_do' ); ?>
    <input type="hidden" name="page" value="stp-brain">
    <input type="hidden" name="stp_do" value="processai">
    <button class="button button-small" <?php disabled( $pending <= 0 ); ?>>Process Pending AI Now</button>
  </form>
</div>

<div class="stp-an-card">
  <h3 class="stp-an-title">AI Decision Meaning</h3>
  <table class="stp-threat-tbl"><thead><tr><th>AI Label</th><th>What It Means</th><th>SecureTrack Action</th></tr></thead><tbody>
    <tr><td><span class="stp-sc rg">clean</span></td><td>Expected or harmless pattern.</td><td>Stored in AI trail only.</td></tr>
    <tr><td><span class="stp-sc rg">protected</span></td><td>Threat-like request was already denied, blocked, redirected, or rate-limited.</td><td>Visible in Protections, no extra alert.</td></tr>
    <tr><td><span class="stp-sc ry">suspicious</span></td><td>Needs watchlist visibility, but evidence is not strong enough for escalation.</td><td>Stored in AI trail.</td></tr>
    <tr><td><span class="stp-sc rr">critical</span></td><td>AI believes this is a missed attack, likely compromise, or urgent escalation.</td><td>Raises an Alert linked to the original event.</td></tr>
  </tbody></table>
</div>

<div class="stp-an-grid" style="grid-template-columns:1fr 1fr">
  <div class="stp-an-card">
    <h3 class="stp-an-title">Risky Patterns Learned</h3>
    <table class="stp-threat-tbl"><thead><tr><th>Feature</th><th>Good</th><th>Risk</th><th>Confidence</th></tr></thead><tbody>
    <?php if ( ! $risky ): ?><tr><td colspan="4">No risky learned patterns yet.</td></tr><?php endif; ?>
    <?php foreach ( $risky as $row ): $meta = json_decode( (string) $row->meta, true ); ?>
      <tr><td><strong><?php echo esc_html( $row->feature_type ); ?></strong><br><small><?php echo esc_html( $meta['sample'] ?? $row->feature_key ); ?></small></td><td><?php echo number_format( (int) $row->good_count ); ?></td><td class="rr"><?php echo number_format( (int) $row->risk_count ); ?></td><td><?php echo (int) $row->confidence; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="stp-an-card">
    <h3 class="stp-an-title">Known Normal Patterns</h3>
    <table class="stp-threat-tbl"><thead><tr><th>Feature</th><th>Good</th><th>Risk</th><th>Confidence</th></tr></thead><tbody>
    <?php if ( ! $normal ): ?><tr><td colspan="4">Normal patterns will appear as the site receives clean traffic.</td></tr><?php endif; ?>
    <?php foreach ( $normal as $row ): $meta = json_decode( (string) $row->meta, true ); ?>
      <tr><td><strong><?php echo esc_html( $row->feature_type ); ?></strong><br><small><?php echo esc_html( $meta['sample'] ?? $row->feature_key ); ?></small></td><td class="rg"><?php echo number_format( (int) $row->good_count ); ?></td><td><?php echo number_format( (int) $row->risk_count ); ?></td><td><?php echo (int) $row->confidence; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
</div>

<div class="stp-an-card">
  <h3 class="stp-an-title">AI Review Trail</h3>
  <p class="stp-desc">AI reviews compact uncertain-event packets. Clean/suspicious/critical decisions are stored here; critical AI findings also raise linked alerts without rewriting old event-log rows.</p>
  <table class="stp-threat-tbl"><thead><tr><th>Time</th><th>Status</th><th>Event</th><th>IP</th><th>Local / AI</th><th>Compact Context</th></tr></thead><tbody>
  <?php if ( ! $queue ): ?><tr><td colspan="6">No uncertain events queued yet.</td></tr><?php endif; ?>
  <?php foreach ( $queue as $q ): $ctx = json_decode( (string) $q->compact_context, true ); ?>
    <tr>
      <td><small><?php echo esc_html( date( 'M j H:i', strtotime( $q->created_at ) ) ); ?></small></td>
      <td><span class="stp-tag"><?php echo esc_html( $q->status ); ?></span><br><small><?php echo esc_html( $q->provider ); ?></small><?php echo ! empty( $q->ai_reason ) && $q->status !== 'reviewed' ? '<br><small class="stp-rr">' . esc_html( substr( $q->ai_reason, 0, 120 ) ) . '</small>' : ''; ?></td>
      <td><?php echo esc_html( $q->event_type ?: ( $ctx['e'] ?? 'event' ) ); ?><?php echo $q->event_sub ? '<br><small>' . esc_html( $q->event_sub ) . '</small>' : ''; ?></td>
      <td><?php echo $q->ip_address ? stp_ip_link( $q->ip_address ) : '—'; ?></td>
      <td><span class="stp-sc <?php echo (int) $q->local_score >= 60 ? 'rr' : 'ry'; ?>"><?php echo (int) $q->local_score; ?></span><?php echo $q->ai_score !== null ? '<br><small>AI ' . esc_html( $q->ai_label ) . ' ' . (int) $q->ai_score . ': ' . esc_html( $q->ai_reason ) . '</small>' : ''; ?></td>
      <td><small><?php echo esc_html( substr( wp_json_encode( $ctx ), 0, 220 ) ); ?></small></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: SUBNET INTELLIGENCE
// ════════════════════════════════════════════════════════════════

function stp_pg_subnets() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;
	$rows = $wpdb->get_results( "SELECT * FROM " . stp_t( 'subnets' ) . " ORDER BY is_banned DESC, threat_score DESC, last_seen DESC LIMIT 300" );
	$threshold = (int) ( stp_cfg()['subnet_alert_at'] ?? 2 );
	?>
<div class="wrap stp-wrap">
<h1>Subnet Intelligence</h1>
<p class="stp-desc">Groups IPv4 traffic by /24 range. When <?php echo esc_html( $threshold ); ?> or more nearby IPs probe attack paths, SecureTrack raises a coordinated subnet alert and lets you ban the full range.</p>
<div class="stp-tw"><table class="stp-t">
<thead><tr><th>Subnet</th><th>Threat</th><th>IPs</th><th>Attack Events</th><th>First / Last Seen</th><th>Ban Status</th><th>Actions</th></tr></thead>
<tbody>
<?php if ( ! $rows ): ?>
  <tr><td colspan="7">No subnet data yet. It will appear as traffic is logged.</td></tr>
<?php endif; ?>
<?php foreach ( $rows as $s ):
	$level = $s->threat_level ?: 'low';
	$rc = $level === 'critical' ? 'rr' : ( $level === 'high' ? 'ry' : 'rg' );
	$peers = $wpdb->get_col( $wpdb->prepare(
		"SELECT ip_address FROM " . stp_t( 'ips' ) . " WHERE ip_address LIKE %s ORDER BY risk_score DESC,last_seen DESC LIMIT 6",
		stp_subnet_like( $s->subnet )
	) );
?>
<tr class="<?php echo $s->is_banned ? 'stp-ip-blocked' : ''; ?>">
  <td><code><?php echo esc_html( $s->subnet ); ?></code><br><small><?php echo wp_kses_post( implode( ', ', array_map( 'stp_ip_link', $peers ) ) ); ?></small></td>
  <td><span class="stp-sc <?php echo esc_attr( $rc ); ?>"><?php echo (int) $s->threat_score; ?></span><br><small><?php echo esc_html( ucfirst( $level ) ); ?></small></td>
  <td><?php echo number_format( (int) $s->ip_count ); ?> tracked<br><small><?php echo number_format( (int) $s->attack_ips ); ?> attacking</small></td>
  <td><?php echo number_format( (int) $s->attack_events ); ?> / <?php echo number_format( (int) $s->total_events ); ?></td>
  <td><small><?php echo esc_html( $s->first_seen ? date( 'M j H:i', strtotime( $s->first_seen ) ) : '—' ); ?><br><?php echo esc_html( $s->last_seen ? date( 'M j H:i', strtotime( $s->last_seen ) ) : '—' ); ?></small></td>
  <td><?php echo $s->is_banned ? '<strong style="color:#dc2626">BANNED</strong><br><small>' . esc_html( $s->ban_reason ) . '</small>' : '<span style="color:#059669">Active</span>'; ?></td>
  <td class="stp-ac">
    <?php if ( $s->is_banned ): ?>
      <button class="button button-small" onclick="stpUnbanSubnet('<?php echo esc_js( $s->subnet ); ?>')">Unban</button>
    <?php else: ?>
      <button class="button button-small button-primary" onclick="stpBanSubnet('<?php echo esc_js( $s->subnet ); ?>')">Ban All</button>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: CHAIN LINK IP INVESTIGATOR
// ════════════════════════════════════════════════════════════════

function stp_chain_verdict( $ip_row, $stats, $decision, $subnet_row ) {
	if ( ! empty( $decision['blocked'] ) ) return array( 'label' => 'Contained', 'class' => 'rg', 'why' => 'SecureTrack is denying this IP because of ' . str_replace( '_', ' ', $decision['source'] ?? 'block policy' ) . '.' );
	if ( ( $ip_row->status ?? '' ) === 'trusted' && empty( $stats['hard_attacks'] ) ) {
		if ( (int) ( $stats['red'] ?? 0 ) > 0 || (int) ( $ip_row->risk_score ?? 0 ) >= 50 ) return array( 'label' => 'Trusted / Watch', 'class' => 'ry', 'why' => 'This IP is trusted, but SecureTrack is still auditing baseline anomalies.' );
		return array( 'label' => 'Trusted', 'class' => 'rg', 'why' => 'This IP is trusted and no hard attack evidence is visible.' );
	}
	if ( (int) ( $stats['critical'] ?? 0 ) > 0 || (int) ( $stats['red'] ?? 0 ) > 0 || (int) ( $ip_row->risk_score ?? 0 ) >= 80 ) return array( 'label' => 'Critical', 'class' => 'rr', 'why' => 'This IP has critical/red behavior or a very high risk score.' );
	if ( (int) ( $stats['yellow'] ?? 0 ) > 0 || (int) ( $stats['protections'] ?? 0 ) > 0 || (int) ( $ip_row->risk_score ?? 0 ) >= 30 || ( $subnet_row && (int) $subnet_row->threat_score >= 50 ) ) return array( 'label' => 'Suspicious', 'class' => 'ry', 'why' => 'This IP or its subnet has warning signs, but it is not currently directly blocked.' );
	return array( 'label' => 'Clean / Low Signal', 'class' => 'rg', 'why' => 'No strong threat chain is visible in SecureTrack records yet.' );
}

function stp_pg_chain() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;
	$ip_raw = trim( sanitize_text_field( wp_unslash( $_GET['ip'] ?? '' ) ) );
	$ip = stp_normalize_ip( $ip_raw );
	$valid = $ip !== '';
	?>
<div class="wrap stp-wrap">
<h1>Chain Link</h1>
<p class="stp-desc">Enter any IP to see the full SecureTrack story: events, protections, alerts, subnet intelligence, sessions, users, and why it looks clean, suspicious, contained, or critical.</p>
<div class="stp-bar">
  <form method="get">
    <input type="hidden" name="page" value="stp-chain">
    <input type="text" name="ip" value="<?php echo esc_attr( $ip ); ?>" placeholder="IP address" style="min-width:280px">
    <button class="button button-primary" type="submit">Investigate</button>
  </form>
</div>
<?php if ( $ip_raw && ! $valid ): ?>
  <div class="notice notice-error"><p>Invalid IP address.</p></div>
<?php endif; ?>
<?php
	if ( ! $valid ) {
		echo '</div>';
		return;
	}
	$ip_row = stp_get_ip_row( $ip );
	$subnet = stp_subnet24_cidr( $ip );
	$subnet_row = $subnet ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . stp_t( 'subnets' ) . " WHERE subnet=%s", $subnet ) ) : null;
	$decision = stp_block_decision( $ip );
	$stats = $wpdb->get_row( $wpdb->prepare(
		"SELECT COUNT(*) total,
		        SUM(CASE WHEN e.flag_status='red' THEN 1 ELSE 0 END) red,
		        SUM(CASE WHEN e.flag_status='yellow' THEN 1 ELSE 0 END) yellow,
		        SUM(CASE WHEN e.event_type='protection_block' THEN 1 ELSE 0 END) protections,
		        SUM(CASE WHEN e.risk_score>=90 THEN 1 ELSE 0 END) critical,
		        SUM(CASE WHEN e.event_type IN ('waf_block','login_failed','rest_abuse','xmlrpc','break_glass_access','file_edit') OR e.risk_reasons LIKE %s OR e.risk_reasons LIKE %s THEN 1 ELSE 0 END) hard_attacks,
		        MAX(e.created_at) last_event
		 FROM " . stp_t( 'events' ) . " e
		 JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE i.ip_address=%s",
		'%Attack path probed%', '%Adaptive WAF%', $ip
	), ARRAY_A );
	$stats = array_map( 'intval', array_merge( array( 'total' => 0, 'red' => 0, 'yellow' => 0, 'protections' => 0, 'critical' => 0, 'hard_attacks' => 0 ), (array) $stats ) );
	$verdict = stp_chain_verdict( $ip_row, $stats, $decision, $subnet_row );
	$loc = stp_location_label( $ip_row );
	$events = $wpdb->get_results( $wpdb->prepare(
		"SELECT e.* FROM " . stp_t( 'events' ) . " e JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id WHERE i.ip_address=%s ORDER BY e.created_at DESC LIMIT 200",
		$ip
	) );
	$alerts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . stp_t( 'alerts' ) . " WHERE ip_address=%s OR subnet_24=%s ORDER BY alert_time DESC LIMIT 50", $ip, $subnet ) );
	$sessions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . stp_t( 'sessions' ) . " WHERE ip_id=%d ORDER BY last_activity DESC LIMIT 20", (int) $ip_row->id ) );
	$users = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT username FROM " . stp_t( 'events' ) . " WHERE ip_id=%d AND username IS NOT NULL AND username<>'' ORDER BY username LIMIT 20", (int) $ip_row->id ) );
	$peers = $subnet ? $wpdb->get_results( $wpdb->prepare(
		"SELECT i.ip_address,i.status,i.risk_score,COUNT(e.id) events
		 FROM " . stp_t( 'ips' ) . " i
		 LEFT JOIN " . stp_t( 'events' ) . " e ON e.ip_id=i.id
		 WHERE i.ip_address LIKE %s
		 GROUP BY i.id ORDER BY i.status='blocked' DESC,i.risk_score DESC,events DESC LIMIT 20",
		stp_subnet_like( $subnet )
	) ) : array();
	?>
<div class="stp-grid">
  <div class="stp-card stp-card-<?php echo $verdict['class'] === 'rr' ? 'red' : ( $verdict['class'] === 'ry' ? 'yellow' : 'green' ); ?>"><span class="stp-n"><?php echo esc_html( $verdict['label'] ); ?></span><span class="stp-l"><?php echo esc_html( $verdict['why'] ); ?></span></div>
  <div class="stp-card stp-card-orange"><span class="stp-n"><?php echo esc_html( ! empty( $decision['blocked'] ) ? ucfirst( str_replace( '_', ' ', $decision['source'] ) ) : 'Not Blocked' ); ?></span><span class="stp-l">Effective Access</span></div>
  <div class="stp-card stp-card-blue"><span class="stp-n"><?php echo (int) $ip_row->risk_score; ?></span><span class="stp-l">IP Risk Score</span></div>
  <div class="stp-card stp-card-purple"><span class="stp-n"><?php echo number_format( (int) $stats['total'] ); ?></span><span class="stp-l">Total Events</span></div>
  <div class="stp-card stp-card-green"><span class="stp-n"><?php echo number_format( (int) $stats['protections'] ); ?></span><span class="stp-l">Protections</span></div>
</div>
<div class="stp-an-card" style="margin:12px 0 18px">
  <h2 style="margin-top:0"><?php echo stp_ip_link( $ip ); ?></h2>
  <p><strong>Status:</strong> <?php echo esc_html( ucfirst( $ip_row->status ?? 'unknown' ) ); ?> |
     <strong>Effective block:</strong> <?php echo esc_html( ! empty( $decision['blocked'] ) ? str_replace( '_', ' ', $decision['source'] ) : 'none' ); ?> |
     <strong>Location:</strong> <?php echo esc_html( $loc ?: 'unknown' ); ?> |
     <strong>Subnet:</strong> <?php echo $subnet ? '<code>' . esc_html( $subnet ) . '</code>' : 'IPv6 / not grouped'; ?></p>
  <p><strong>Users seen:</strong> <?php echo esc_html( $users ? implode( ', ', $users ) : 'none' ); ?></p>
  <p class="stp-ac">
    <?php if ( ( $ip_row->status ?? '' ) === 'blocked' ): ?>
      <button class="stp-b stp-bb" onclick="stpUnblock(this,<?php echo (int) $ip_row->id; ?>)">Unblock IP</button>
    <?php else: ?>
      <button class="stp-b stp-bb" onclick="stpBlock(this,<?php echo (int) $ip_row->id; ?>)">Block IP</button>
    <?php endif; ?>
    <?php if ( ( $ip_row->status ?? '' ) === 'trusted' ): ?>
      <button class="stp-b stp-bt stp-disabled" disabled>Trusted</button>
    <?php else: ?>
      <button class="stp-b stp-bt" onclick="stpTrust(this,<?php echo (int) $ip_row->id; ?>)">Trust IP</button>
    <?php endif; ?>
    <?php if ( $subnet ): ?>
      <?php if ( $subnet_row && (int) $subnet_row->is_banned === 1 ): ?>
        <button class="stp-b stp-bb" onclick="stpUnbanSubnet(this,'<?php echo esc_js( $subnet ); ?>')">Unban /24</button>
      <?php else: ?>
        <button class="stp-b stp-bb" onclick="stpBlockRange(this,<?php echo (int) $ip_row->id; ?>)">Ban /24</button>
      <?php endif; ?>
    <?php endif; ?>
  </p>
</div>
<div class="stp-tw"><table class="stp-t"><thead><tr><th>Subnet Intelligence</th><th>Threat</th><th>Nearby IPs</th></tr></thead><tbody>
<tr>
  <td><?php echo $subnet ? '<code>' . esc_html( $subnet ) . '</code>' : 'No IPv4 subnet grouping'; ?></td>
  <td><?php echo $subnet_row ? '<span class="stp-sc ' . esc_attr( (int) $subnet_row->threat_score >= 80 ? 'rr' : ( (int) $subnet_row->threat_score >= 50 ? 'ry' : 'rg' ) ) . '">' . (int) $subnet_row->threat_score . '</span> ' . esc_html( $subnet_row->threat_level ) . ( $subnet_row->is_banned ? ' / banned' : '' ) : 'No subnet record yet'; ?></td>
  <td><?php echo $peers ? wp_kses_post( implode( ', ', array_map( function( $p ) { return stp_ip_link( $p->ip_address ) . ' <small>(' . esc_html( $p->status ) . ', risk ' . (int) $p->risk_score . ')</small>'; }, $peers ) ) ) : 'No nearby tracked IPs'; ?></td>
</tr>
</tbody></table></div>
<h2>Timeline</h2>
<div class="stp-tw"><table class="stp-t"><thead><tr><th>Time</th><th>Event</th><th>Risk</th><th>Target/Object</th><th>Why / Evidence</th></tr></thead><tbody>
<?php if ( ! $events ): ?><tr><td colspan="5">No events recorded for this IP yet.</td></tr><?php endif; ?>
<?php foreach ( $events as $e ): $extra = json_decode( (string) $e->extra, true ); $extra = is_array( $extra ) ? $extra : array(); ?>
<tr class="stp-r-<?php echo esc_attr( $e->flag_status ); ?>">
  <td><small><?php echo esc_html( date( 'M j H:i:s', strtotime( $e->created_at ) ) ); ?></small></td>
  <td><strong><?php echo esc_html( $e->event_type ); ?></strong><?php echo $e->event_sub ? '<br><small>' . esc_html( $e->event_sub ) . '</small>' : ''; ?></td>
  <td><span class="stp-sc <?php echo (int) $e->risk_score >= 60 ? 'rr' : ( (int) $e->risk_score >= 25 ? 'ry' : 'rg' ); ?>"><?php echo (int) $e->risk_score; ?></span><br><small><?php echo esc_html( $e->flag_status ); ?></small></td>
  <td><small><?php echo esc_html( $e->obj_title ?: ( $e->url ? ( wp_parse_url( $e->url, PHP_URL_PATH ) ?: $e->url ) : '—' ) ); ?></small></td>
  <td><small><?php echo esc_html( $e->risk_reasons ?: ( ! empty( $extra['response_shown'] ) ? $extra['response_shown'] : '' ) ); ?><?php echo ! empty( $extra['payload_flags'] ) ? '<br>Payload: ' . esc_html( implode( ', ', (array) $extra['payload_flags'] ) ) : ''; ?></small></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<h2>Alerts & Sessions</h2>
<div class="stp-tw"><table class="stp-t"><thead><tr><th>Alerts</th><th>Recent Sessions</th></tr></thead><tbody><tr>
<td><?php echo $alerts ? wp_kses_post( implode( '<br>', array_map( function( $a ) { return '<strong>' . esc_html( strtoupper( $a->severity ) ) . '</strong> ' . esc_html( $a->title ) . ' <small>' . esc_html( $a->alert_time ) . '</small>'; }, $alerts ) ) ) : 'No linked alerts'; ?></td>
<td><?php echo $sessions ? wp_kses_post( implode( '<br>', array_map( function( $s ) { return esc_html( $s->started_at . ' -> ' . $s->last_activity ) . ' <small>' . esc_html( $s->device_type . ', pages ' . $s->page_count . ', seconds ' . $s->total_seconds ) . '</small>'; }, $sessions ) ) ) : 'No sessions'; ?></td>
</tr></tbody></table></div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: FILE SCANNER
// ════════════════════════════════════════════════════════════════

function stp_file_scan_patterns() {
	return array(
		'eval_base64'      => '/eval\s*\(\s*base64_decode\s*\(/i',
		'assert_payload'   => '/assert\s*\(\s*\$_(?:POST|GET|REQUEST|COOKIE)/i',
		'shell_command'    => '/\b(?:shell_exec|passthru|proc_open|popen|system)\s*\(/i',
		'obfuscated_gzip'  => '/(?:gzinflate|gzuncompress|str_rot13)\s*\(/i',
		'remote_include'   => '/(?:include|require)(?:_once)?\s*\(?\s*[\'"]https?:\/\//i',
		'php_in_uploads'   => '/\/uploads\/.*\.(?:php|phtml|php\d)$/i',
		'htaccess_exec'    => '/AddType\s+application\/x-httpd-php|SetHandler\s+application\/x-httpd-php/i',
	);
}

function stp_scan_files_now( $limit = 1200 ) {
	if ( get_transient( 'stp_file_scan_lock' ) ) {
		$last = (array) get_transient( 'stp_file_scan_last' );
		$last['locked'] = true;
		return $last + array( 'scanned' => 0, 'findings' => array() );
	}
	set_transient( 'stp_file_scan_lock', 1, 10 * MINUTE_IN_SECONDS );
	$base = WP_CONTENT_DIR;
	$skip = '/\/(?:cache|upgrade|backups?|backup|ai1wm-backups|wflogs|updraft|node_modules|vendor)\//i';
	$exts = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'js', 'htaccess' );
	$patterns = stp_file_scan_patterns();
	$findings = array();
	$scanned = 0;

	try {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $scanned >= $limit ) break;
			if ( ! $file->isFile() ) continue;
			$path = wp_normalize_path( $file->getPathname() );
			if ( $file->isLink() || strpos( $path, wp_normalize_path( WP_CONTENT_DIR ) ) !== 0 ) continue;
			if ( preg_match( $skip, $path ) ) continue;
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( basename( $path ) !== '.htaccess' && ! in_array( $ext, $exts, true ) ) continue;
			$scanned++;
			$rel = str_replace( wp_normalize_path( ABSPATH ), '', $path );
			$size = (int) @filesize( $path );
			if ( $size > 1500000 ) {
				$head = @file_get_contents( $path, false, null, 0, 750000 );
				$tail = @file_get_contents( $path, false, null, max( 0, $size - 750000 ), 750000 );
				$body = ( $head === false ? '' : $head ) . "\n/* securetrack-large-file-gap */\n" . ( $tail === false ? '' : $tail );
			} else {
				$body = @file_get_contents( $path );
			}
			if ( $body === false ) continue;
			$hits = array();
			foreach ( $patterns as $name => $rx ) {
				if ( $name === 'php_in_uploads' ) {
					if ( preg_match( $rx, $path ) ) $hits[] = $name;
				} elseif ( preg_match( $rx, $body ) ) {
					$hits[] = $name;
				}
			}
			$recent = filemtime( $path ) > strtotime( '-7 days' );
			if ( $hits || $recent ) {
				$findings[] = array(
					'file'     => $rel,
					'hits'     => $hits,
					'recent'   => $recent,
					'modified' => date_i18n( 'M j Y H:i', filemtime( $path ) ),
					'size'     => size_format( $size ),
				);
			}
		}
	} catch ( Exception $e ) {
		delete_transient( 'stp_file_scan_lock' );
		return array( 'error' => $e->getMessage(), 'scanned' => $scanned, 'findings' => $findings );
	}

	set_transient( 'stp_file_scan_last', array( 'time' => current_time( 'mysql' ), 'scanned' => $scanned, 'findings' => $findings ), 6 * HOUR_IN_SECONDS );
	delete_transient( 'stp_file_scan_lock' );
	if ( $findings ) {
		stp_create_alert( array(
			'chain_type'  => 'file_scan',
			'severity'    => 'high',
			'title'       => 'File scanner found suspicious files',
			'description' => count( $findings ) . ' suspicious or recently changed file(s) were found in wp-content.',
			'evidence'    => array_slice( $findings, 0, 20 ),
		) );
	}
	return array( 'scanned' => $scanned, 'findings' => $findings );
}

function stp_pg_filescan() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$result = get_transient( 'stp_file_scan_last' );
	if ( isset( $_GET['scan'] ) && check_admin_referer( 'stp_file_scan' ) ) {
		$result = stp_scan_files_now();
		echo ! empty( $result['locked'] )
			? '<div class="notice notice-warning is-dismissible"><p>A SecureTrack file scan is already running. Showing the last completed scan.</p></div>'
			: '<div class="notice notice-success is-dismissible"><p>File scan completed.</p></div>';
	}
	$findings = $result['findings'] ?? array();
	?>
<div class="wrap stp-wrap">
<h1>File Scanner</h1>
<p class="stp-desc">Manual heuristic scan for suspicious PHP/JS/.htaccess patterns and recent changes under <code>wp-content</code>. Review findings before deleting anything.</p>
<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( '?page=stp-filescan&scan=1', 'stp_file_scan' ) ); ?>">Run Scan Now</a>
<?php if ( $result ): ?><span class="stp-tot">Last scan: <?php echo esc_html( $result['time'] ?? current_time( 'mysql' ) ); ?>, scanned <?php echo number_format( (int) ( $result['scanned'] ?? 0 ) ); ?> files</span><?php endif; ?></p>
<div class="stp-tw"><table class="stp-t">
<thead><tr><th>File</th><th>Signals</th><th>Modified</th><th>Size</th></tr></thead>
<tbody>
<?php if ( ! $findings ): ?>
  <tr><td colspan="4">No suspicious files found in the last scan.</td></tr>
<?php endif; ?>
<?php foreach ( $findings as $f ): ?>
  <tr class="<?php echo ! empty( $f['hits'] ) ? 'stp-r-red' : 'stp-r-yellow'; ?>">
    <td><code><?php echo esc_html( $f['file'] ); ?></code></td>
    <td><?php echo ! empty( $f['hits'] ) ? esc_html( implode( ', ', $f['hits'] ) ) : 'recently modified'; ?></td>
    <td><?php echo esc_html( $f['modified'] ); ?></td>
    <td><?php echo esc_html( $f['size'] ); ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: SESSIONS
// ════════════════════════════════════════════════════════════════

function stp_pg_sessions() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;

	$ff   = sanitize_text_field( $_GET['ff'] ?? '' );
	$w    = array( '1=1' ); $v = array();
	if ( $ff ) { $w[] = 's.flag_status=%s'; $v[] = $ff; }
	$ws   = implode( ' AND ', $w );
	$sql  = "SELECT s.*,i.ip_address,i.country,i.country_code,i.city,u.display_name FROM " . stp_t('sessions') . " s LEFT JOIN " . stp_t('ips') . " i ON i.id=s.ip_id LEFT JOIN {$wpdb->users} u ON u.ID=s.user_id WHERE {$ws} ORDER BY s.last_activity DESC LIMIT 100";
	$rows = empty($v) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql,...$v));
	$pages_by_session = array();
	$session_ids = array_map( 'intval', wp_list_pluck( (array) $rows, 'id' ) );
	if ( $session_ids ) {
		$id_sql = implode( ',', $session_ids );
		$all_pages = $wpdb->get_results( "SELECT session_id,url,time_spent,visited_at FROM " . stp_t( 'pages' ) . " WHERE session_id IN ({$id_sql}) ORDER BY session_id,visited_at LIMIT 3000" );
		foreach ( (array) $all_pages as $pg ) {
			$sid = (int) $pg->session_id;
			if ( empty( $pages_by_session[ $sid ] ) ) $pages_by_session[ $sid ] = array();
			if ( count( $pages_by_session[ $sid ] ) < 30 ) $pages_by_session[ $sid ][] = $pg;
		}
	}
	?>
<div class="wrap stp-wrap">
<h1>📡 Visitor Sessions</h1>
<div class="stp-bar"><form method="get">
  <input type="hidden" name="page" value="stp-sessions">
  <select name="ff">
    <option value="">All Flags</option>
    <?php foreach ( array('red'=>'🔴 Red','yellow'=>'🟡 Yellow','green'=>'🟢 Green') as $fv=>$fl ): ?>
      <option value="<?php echo $fv; ?>" <?php selected($ff,$fv); ?>><?php echo $fl; ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="button button-primary">Filter</button>
  <a href="?page=stp-sessions" class="button">Clear</a>
</form></div>

<div class="stp-tw"><table class="stp-t">
<thead><tr>
  <th>Flag</th><th>User / Visitor</th><th>IP / Location</th><th>Device</th>
  <th>Pages</th><th>Duration</th><th>Started</th><th>Last Active</th><th>Navigation Trail</th>
</tr></thead>
<tbody>
<?php foreach ( $rows as $s ):
  $fi   = array('green'=>'🟢','yellow'=>'🟡','red'=>'🔴')[$s->flag_status] ?? '⚪';
  $dur  = (int)$s->total_seconds > 0 ? gmdate('H:i:s',(int)$s->total_seconds) : '—';
  $loc  = stp_location_label( $s );
  $pages = $pages_by_session[ (int) $s->id ] ?? array();
?>
<tr class="stp-r-<?php echo esc_attr($s->flag_status); ?>">
  <td class="stp-fc"><?php echo $fi; ?></td>
  <td><?php echo $s->display_name ? esc_html($s->display_name) : ( (int)$s->user_id > 0 ? "User #{$s->user_id}" : '<em class="stp-vis">visitor</em>' ); ?></td>
  <td><?php echo stp_ip_link( $s->ip_address ?? '' ); ?><br><small><?php echo esc_html($loc); ?></small></td>
  <td><small><?php echo esc_html($s->device_type ?? 'desktop'); ?><?php echo $s->is_bot ? ' 🤖' : ''; ?></small></td>
  <td><?php echo (int)$s->page_count; ?></td>
  <td><?php echo $dur; ?></td>
  <td><small><?php echo esc_html( date('M j H:i', strtotime($s->started_at)) ); ?></small></td>
  <td><small><?php echo esc_html( date('M j H:i', strtotime($s->last_activity)) ); ?></small></td>
  <td>
    <?php if ( ! empty($pages) ): ?>
    <details>
      <summary><?php echo count($pages); ?> page(s) visited</summary>
      <ol class="stp-pl">
        <?php foreach ( $pages as $pg ): ?>
          <li>
            <span class="stp-purl"><?php echo esc_html( parse_url($pg->url, PHP_URL_PATH) ?: $pg->url ); ?></span>
            <small class="stp-pt"><?php echo (int)$pg->time_spent; ?>s</small>
          </li>
        <?php endforeach; ?>
      </ol>
    </details>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: USER BEHAVIORAL PROFILES
// ════════════════════════════════════════════════════════════════

function stp_pg_profiles() {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) return;

	$profiles = $wpdb->get_results(
		"SELECT p.*,u.display_name,u.user_login,u.user_email
		 FROM " . stp_t('profiles') . " p
		 JOIN {$wpdb->users} u ON u.ID=p.user_id
		 ORDER BY p.risk_count DESC, p.updated_at DESC"
	);
	?>
<div class="wrap stp-wrap">
<h1>👤 Behavioral Profiles <span class="stp-tagline-inline">— learning engine</span></h1>
<p class="stp-desc">
  SecureTrack builds a behavioral baseline per user by observing login times, IPs, and countries.
  Once <strong>10+ clean events</strong> are recorded, deviations from the baseline raise the risk score automatically.
</p>

<div class="stp-tw"><table class="stp-t">
<thead><tr>
  <th>User</th><th>Baseline</th><th>Known IPs</th><th>Countries</th>
  <th>Peak Login Hours</th><th>🟢 Clean Events</th><th>🔴 Risk Events</th>
  <th>Last IP</th><th>Last Login</th>
</tr></thead>
<tbody>
<?php foreach ( $profiles as $p ):
  $tips = json_decode($p->trusted_ips ?? '[]', true) ?: array();
  $tc   = json_decode($p->trusted_countries ?? '[]', true) ?: array();
  $lh   = json_decode($p->login_hours ?? '{}', true) ?: array();
  arsort($lh); $th = array_slice(array_keys($lh),0,4);
  $hrs  = implode(', ', array_map(function($h){ return sprintf('%02d:00',$h); },$th));
  $rr   = (int)$p->green_count > 0 ? round( (int)$p->risk_count / max(1,(int)$p->green_count) * 100 ) : 0;
?>
<tr>
  <td>
    <strong><?php echo esc_html($p->display_name ?: $p->user_login); ?></strong>
    <br><small class="stp-email"><?php echo esc_html($p->user_email); ?></small>
  </td>
  <td><?php echo $p->baseline_done ? '<span class="stp-tag stp-tag-ok">✓ Ready</span>' : '<span class="stp-tag">Learning…</span>'; ?></td>
  <td>
    <?php echo count($tips); ?> IPs
    <br><small><?php echo esc_html( implode(', ', array_slice($tips,0,3)) . (count($tips)>3?'…':'') ); ?></small>
  </td>
  <td><?php echo esc_html(implode(', ',$tc) ?: '—'); ?></td>
  <td><small><?php echo esc_html($hrs ?: '—'); ?></small></td>
  <td class="rg"><strong><?php echo (int)$p->green_count; ?></strong></td>
  <td class="<?php echo $rr>20?'rr':($rr>10?'ry':''); ?>">
    <strong><?php echo (int)$p->risk_count; ?></strong>
    <?php echo $rr > 0 ? "<small>({$rr}%)</small>" : ''; ?>
  </td>
  <td><?php echo $p->last_ip ? stp_ip_link( $p->last_ip ) : '—'; ?></td>
  <td><small><?php echo $p->last_login ? esc_html(date('M j H:i',strtotime($p->last_login))) : '—'; ?></small></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN PAGE: SETTINGS
// ════════════════════════════════════════════════════════════════

function stp_pg_settings() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	global $wpdb;

	/* Handle manual cleanup actions */
	if ( isset( $_REQUEST['stp_do'] ) && check_admin_referer( 'stp_do' ) ) {
		$action = sanitize_text_field( wp_unslash( $_REQUEST['stp_do'] ) );
		$is_post = ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST';
		$destructive = in_array( $action, array( 'gc', 'yc', 'unblock', 'reset' ), true );
		if ( $destructive && ! $is_post ) {
			echo '<div class="notice notice-error is-dismissible"><p>Destructive SecureTrack actions must be submitted with POST.</p></div>';
		} elseif ( $action === 'gc' ) {
			$wpdb->query( "DELETE FROM " . stp_t('events') . " WHERE flag_status='green'" );
			echo '<div class="notice notice-success is-dismissible"><p>All green events cleared.</p></div>';
		} elseif ( $action === 'yc' ) {
			$wpdb->query( "DELETE FROM " . stp_t('events') . " WHERE flag_status='yellow'" );
			echo '<div class="notice notice-success is-dismissible"><p>All yellow events cleared.</p></div>';
		} elseif ( $action === 'unblock' ) {
			$wpdb->query( "UPDATE " . stp_t('ips') . " SET status='unknown', blocked_at=NULL WHERE status='blocked'" );
			echo '<div class="notice notice-success is-dismissible"><p>All IPs unblocked.</p></div>';
		} elseif ( $action === 'repairdb' ) {
			$ok = stp_repair_database();
			echo $ok ? '<div class="notice notice-success is-dismissible"><p>SecureTrack database repaired and verified.</p></div>' : '<div class="notice notice-error is-dismissible"><p>SecureTrack database repair did not complete. Check database user permissions.</p></div>';
		} elseif ( $action === 'probe' ) {
			$eid = stp_log( 'diagnostic', array( 'sub' => 'manual_probe', 'url' => admin_url( 'admin.php?page=stp-settings' ), 'extra' => array( 'source' => 'settings_button', 'time' => current_time( 'mysql' ) ) ) );
			echo $eid ? '<div class="notice notice-success is-dismissible"><p>Test event inserted. Check Events Log now.</p></div>' : '<div class="notice notice-error is-dismissible"><p>Test event could not be inserted. Use Repair Database, then try again.</p></div>';
		} elseif ( $action === 'harden' ) {
			$door = sanitize_key( $_GET['door'] ?? '' );
			$allowed = array( 'harden_xmlrpc', 'harden_rest_users', 'harden_author_archives', 'author_public_slugs', 'harden_file_editor', 'harden_wp_generator', 'harden_security_headers' );
			if ( in_array( $door, $allowed, true ) ) {
				$settings = stp_cfg();
				$settings[ $door ] = 1;
				if ( $door === 'author_public_slugs' ) $settings['harden_author_archives'] = 0;
				update_option( 'stp_settings', $settings );
				echo '<div class="notice notice-success is-dismissible"><p>Hardening enabled.</p></div>';
			}
		} elseif ( $action === 'unharden' ) {
			$door = sanitize_key( $_GET['door'] ?? '' );
			$allowed = array( 'harden_xmlrpc', 'harden_rest_users', 'harden_author_archives', 'author_public_slugs', 'harden_file_editor', 'harden_wp_generator', 'harden_security_headers' );
			if ( in_array( $door, $allowed, true ) ) {
				$settings = stp_cfg();
				$settings[ $door ] = 0;
				update_option( 'stp_settings', $settings );
				echo '<div class="notice notice-success is-dismissible"><p>Hardening disabled for that door.</p></div>';
			}
		} elseif ( $action === 'reset' && isset( $_REQUEST['confirmed'] ) ) {
			foreach ( array( 'ips', 'sessions', 'events', 'profiles', 'pages', 'alerts', 'subnets', 'brain', 'ai_queue', 'rate_limits' ) as $t )
				$wpdb->query( "TRUNCATE TABLE " . stp_t($t) );
			echo '<div class="notice notice-success is-dismissible"><p>All SecureTrack data has been reset.</p></div>';
		} elseif ( $action === 'reschedule' ) {
			stp_schedule_crons();
			echo '<div class="notice notice-success is-dismissible"><p>Cron jobs rescheduled.</p></div>';
		} elseif ( $action === 'geonow' ) {
			stp_run_geo();
			echo '<div class="notice notice-success is-dismissible"><p>Geo lookup batch ran. Refresh IP Reputation after a few seconds.</p></div>';
		} elseif ( $action === 'trainbrain' ) {
			$res = stp_brain_train_from_history( 50000 );
			echo ! empty( $res['error'] )
				? '<div class="notice notice-error is-dismissible"><p>Site Brain training did not replace the current model: ' . esc_html( $res['error'] ) . '</p></div>'
				: '<div class="notice notice-success is-dismissible"><p>Site Brain trained from existing events. Processed ' . number_format_i18n( (int) $res['processed'] ) . ' historical events and built ' . number_format_i18n( (int) $res['features'] ) . ' learned features. Events, alerts, protections, IPs, sessions, and subnets were preserved.</p></div>';
		} elseif ( $action === 'testai' ) {
			$res = stp_ai_test_connection();
			echo is_wp_error( $res )
				? '<div class="notice notice-error is-dismissible"><p>AI connection test failed: ' . esc_html( $res->get_error_message() ) . '</p></div>'
				: '<div class="notice notice-success is-dismissible"><p>AI connection test succeeded. Provider returned structured JSON.</p></div>';
		} elseif ( $action === 'fetchmodels' ) {
			$model_provider = sanitize_key( stp_cfg( true )['v2_ai_provider'] ?? 'gemini' );
			$res = stp_ai_fetch_provider_models( $model_provider );
			echo is_wp_error( $res )
				? '<div class="notice notice-error is-dismissible"><p>Model fetch failed: ' . esc_html( $res->get_error_message() ) . '</p></div>'
				: '<div class="notice notice-success is-dismissible"><p>Fetched ' . number_format_i18n( count( $res ) ) . ' model(s) for ' . esc_html( strtoupper( $model_provider ) ) . '.</p></div>';
		} elseif ( $action === 'processai' ) {
			$res = stp_ai_process_pending_queue( 25 );
			$msg = 'AI queue processed. Reviewed ' . number_format_i18n( (int) $res['reviewed'] ) . ' item(s), errors ' . number_format_i18n( (int) $res['errors'] ) . '.';
			if ( ! empty( $res['paused'] ) ) $msg .= ' Processing paused because the provider reported quota/rate limit.';
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		} elseif ( $action === 'regen_break_glass' ) {
			$settings = stp_cfg();
			$settings['break_glass_slug'] = stp_break_glass_generate_slug();
			update_option( 'stp_settings', $settings );
			wp_cache_delete( 'settings', 'securetrack_pro' );
			echo '<div class="notice notice-success is-dismissible"><p>Break glass login slug regenerated. Store the new recovery URL somewhere safe.</p></div>';
		} elseif ( $action === 'safemode_on' || $action === 'safemode_off' ) {
			$settings = stp_cfg();
			$settings['emergency_safe_mode'] = $action === 'safemode_on' ? 1 : 0;
			update_option( 'stp_settings', $settings );
			wp_cache_delete( 'settings', 'securetrack_pro' );
			echo $settings['emergency_safe_mode']
				? '<div class="notice notice-warning is-dismissible"><p>SecureTrack emergency monitor-only mode is ON. Denials, auto-blocks, endpoint limits, country-login denial, WAF blocks, and tarpits are paused while logging remains available.</p></div>'
				: '<div class="notice notice-success is-dismissible"><p>SecureTrack enforcement resumed.</p></div>';
		}
	}

	/* Save settings */
	if ( isset( $_POST['stp_save'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['stp_settings_nonce'] ?? '' ) ), 'stp_settings_save' ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>SecureTrack settings were not saved because the page token expired. Refresh this settings page and save again.</p></div>';
	} elseif ( isset( $_POST['stp_save'] ) ) {
		$raw_old_settings = (array) get_option( 'stp_settings', array() );
		$old_settings = stp_cfg();
		$provider = sanitize_key( $_POST['v2_ai_provider'] ?? 'none' );
		if ( ! in_array( $provider, array( 'none', 'gemini', 'groq', 'xai' ), true ) ) $provider = 'none';
		$ai_mode = sanitize_key( $_POST['v2_ai_mode'] ?? 'batch' );
		if ( ! in_array( $ai_mode, array( 'batch', 'always' ), true ) ) $ai_mode = 'batch';
		$ai_key = trim( (string) ( $_POST['v2_ai_key'] ?? '' ) );
		$stored_ai_key = $ai_key !== '' ? sanitize_text_field( $ai_key ) : (string) ( $old_settings['v2_ai_key'] ?? '' );
		$stored_ai_key_enc = (string) ( $raw_old_settings['v2_ai_key_enc'] ?? '' );
		if ( $stored_ai_key !== '' ) {
			$candidate_ai_key = stp_encrypt_secret( $stored_ai_key );
			if ( '' !== $candidate_ai_key ) $stored_ai_key_enc = $candidate_ai_key;
		}
		$posted_ai_model = preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) ( $_POST['v2_ai_model'] ?? ( $old_settings['v2_ai_model'] ?? '' ) ) );
		if ( $provider !== ( $old_settings['v2_ai_provider'] ?? 'none' ) || $posted_ai_model === '' ) {
			$provider_defaults = stp_ai_default_models( $provider );
			$posted_ai_model = ! empty( $provider_defaults[0]['name'] ) ? (string) $provider_defaults[0]['name'] : '';
		}
		$posted_idle_roles = isset( $_POST['idle_timeout_roles'] ) && is_array( $_POST['idle_timeout_roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['idle_timeout_roles'] ) ) : array();
		$valid_idle_roles = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : array();
		$posted_idle_roles = array_values( array_intersect( $posted_idle_roles, $valid_idle_roles ) );

		$new = array(
			'red_threshold'     => min(100, max(1,  (int)$_POST['red_threshold'])),
			'yellow_threshold'  => min(100, max(1,  (int)$_POST['yellow_threshold'])),
			'green_trim_days'   => max( 0, (int)$_POST['green_trim_days'] ),
			'yellow_trim_days'  => max( 0, (int)$_POST['yellow_trim_days'] ),
			'alert_email'       => sanitize_email( $_POST['alert_email'] ),
			'alert_on_red'      => (int) isset( $_POST['alert_on_red'] ),
			'geo_enabled'       => (int) isset( $_POST['geo_enabled'] ),
			'country_blocklist' => strtoupper( preg_replace( '/[^A-Z,\s]/i', '', (string) ( $_POST['country_blocklist'] ?? '' ) ) ),
			'login_country_policy' => in_array( sanitize_key( $_POST['login_country_policy'] ?? 'off' ), array( 'off', 'deny', 'ban' ), true ) ? sanitize_key( $_POST['login_country_policy'] ?? 'off' ) : 'off',
			'login_allowed_countries' => strtoupper( preg_replace( '/[^A-Z,\s]/i', '', (string) ( $_POST['login_allowed_countries'] ?? '' ) ) ),
			'emergency_safe_mode' => (int) isset( $_POST['emergency_safe_mode'] ),
			'track_visitors'    => (int) isset( $_POST['track_visitors'] ),
			'track_pages'       => (int) isset( $_POST['track_pages'] ),
			'block_brute_force' => (int) isset( $_POST['block_brute_force'] ),
			'brute_force_limit' => max( 3, (int)$_POST['brute_force_limit'] ),
			'adaptive_waf'      => (int) isset( $_POST['adaptive_waf'] ),
			'waf_block_score'   => max( 50, min( 100, (int) ( $_POST['waf_block_score'] ?? 80 ) ) ),
			'honeypot_enabled'  => (int) isset( $_POST['honeypot_enabled'] ),
			'tarpit_enabled'    => (int) isset( $_POST['tarpit_enabled'] ),
			'exclude_admin'     => (int) isset( $_POST['exclude_admin'] ),
			'harden_xmlrpc'        => (int) isset( $_POST['harden_xmlrpc'] ),
			'harden_rest_users'    => (int) isset( $_POST['harden_rest_users'] ),
			'harden_author_archives'=> (int) isset( $_POST['harden_author_archives'] ),
			'author_public_slugs'  => (int) isset( $_POST['author_public_slugs'] ),
			'harden_file_editor'   => (int) isset( $_POST['harden_file_editor'] ),
			'harden_wp_generator'  => (int) isset( $_POST['harden_wp_generator'] ),
			'harden_security_headers' => (int) isset( $_POST['harden_security_headers'] ),
			'csp_enabled'          => (int) isset( $_POST['csp_enabled'] ),
			'csp_report_only'      => (int) isset( $_POST['csp_report_only'] ),
			'csp_report_uri'       => stp_same_site_url( $_POST['csp_report_uri'] ?? '' ),
			'security_txt_enabled' => (int) isset( $_POST['security_txt_enabled'] ),
			'endpoint_rate_limits' => (int) isset( $_POST['endpoint_rate_limits'] ),
			'rl_login_per_min'     => max( 5, min( 2000, (int) ( $_POST['rl_login_per_min'] ?? 20 ) ) ),
			'rl_xmlrpc_per_min'    => max( 5, min( 2000, (int) ( $_POST['rl_xmlrpc_per_min'] ?? 15 ) ) ),
			'rl_rest_per_min'      => max( 5, min( 2000, (int) ( $_POST['rl_rest_per_min'] ?? 90 ) ) ),
			'rl_admin_per_min'     => max( 5, min( 2000, (int) ( $_POST['rl_admin_per_min'] ?? 120 ) ) ),
			'rl_frontend_per_min'  => max( 5, min( 2000, (int) ( $_POST['rl_frontend_per_min'] ?? 240 ) ) ),
			'idle_timeout_mins'    => max( 0, min( 1440, (int) ( $_POST['idle_timeout_mins'] ?? 0 ) ) ),
			'idle_timeout_roles'   => $posted_idle_roles,
			'adaptive_learning'    => (int) isset( $_POST['adaptive_learning'] ),
			'behavioral_risk'      => (int) isset( $_POST['behavioral_risk'] ),
			'attack_graph'         => (int) isset( $_POST['attack_graph'] ),
			'track_admin_activity' => (int) isset( $_POST['track_admin_activity'] ),
			'subnet_intel'         => (int) isset( $_POST['subnet_intel'] ),
			'chain_detection'      => (int) isset( $_POST['chain_detection'] ),
			'chain_window_mins'    => max( 5, min( 1440, (int) ( $_POST['chain_window_mins'] ?? 60 ) ) ),
			'subnet_alert_at'      => max( 2, min( 20, (int) ( $_POST['subnet_alert_at'] ?? 2 ) ) ),
			'break_glass_slug'     => stp_sanitize_break_glass_slug( $_POST['break_glass_slug'] ?? ( stp_cfg()['break_glass_slug'] ?? '' ) ),
			'v2_site_brain'        => (int) isset( $_POST['v2_site_brain'] ),
			'v2_ai_provider'       => $provider,
			'v2_ai_model'          => $posted_ai_model,
			'v2_ai_mode'           => $ai_mode,
			'v2_ai_key'            => '',
			'v2_ai_key_enc'        => $stored_ai_key_enc,
			'v2_ai_batch_mins'     => max( 1, min( 60, (int) ( $_POST['v2_ai_batch_mins'] ?? 5 ) ) ),
			'v2_uncertain_low'     => max( 1, min( 95, (int) ( $_POST['v2_uncertain_low'] ?? 30 ) ) ),
			'v2_uncertain_high'    => max( 2, min( 99, (int) ( $_POST['v2_uncertain_high'] ?? 70 ) ) ),
			'v2_auto_block_local'  => (int) ! empty( $_POST['v2_auto_block_local'] ),
			'v2_share_patterns'    => (int) ! empty( $_POST['v2_share_patterns'] ),
		);
		if ( $new['v2_uncertain_high'] <= $new['v2_uncertain_low'] ) $new['v2_uncertain_high'] = min( 99, $new['v2_uncertain_low'] + 20 );
		$new = \DSA\Secure\SecureTrack_Settings_Policy::normalize_runtime_config( $new );
		if ( ! \DSA\Secure\SecureTrack_Settings_Policy::kiwe_allows_auto_logout() ) {
			$new['idle_timeout_mins'] = 0;
			$new['idle_timeout_roles'] = array();
		}
		update_option( 'stp_settings', $new );
		wp_cache_delete( 'settings', 'securetrack_pro' );
		if ( $new['v2_ai_provider'] !== ( $old_settings['v2_ai_provider'] ?? 'none' ) || $new['v2_ai_model'] !== ( $old_settings['v2_ai_model'] ?? '' ) || $ai_key !== '' ) {
			stp_ai_status( array( 'connected' => 0, 'provider' => $new['v2_ai_provider'], 'model' => $new['v2_ai_model'], 'message' => $new['v2_ai_provider'] === 'none' ? 'AI provider is set to None.' : 'Key/model saved. Run Test AI Connection.' ) );
		}
		if ( in_array( $new['v2_ai_provider'], array( 'gemini', 'groq', 'xai' ), true ) && $ai_key !== '' ) {
			stp_ai_fetch_provider_models( $new['v2_ai_provider'], $stored_ai_key );
		}
		/* Save webhook fields encrypted because webhook URLs often contain bearer tokens. */
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
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}

	$s = stp_cfg( true );
	$ai_status = stp_ai_status();
	$ai_models_provider = sanitize_key( $s['v2_ai_provider'] ?? 'gemini' );
	$ai_models = (array) get_option( stp_ai_models_option_key( $ai_models_provider ), array() );
	if ( empty( $ai_models ) ) $ai_models = stp_ai_default_models( $ai_models_provider );
	$ai_models_status = stp_ai_models_status();
	$posture = stp_security_posture();
	$diag = (array) get_option( 'stp_diag', array() );
	$last_break_glass = stp_table_exists( 'events' ) ? $wpdb->get_row(
		"SELECT e.created_at,i.ip_address
		 FROM " . stp_t( 'events' ) . " e
		 LEFT JOIN " . stp_t( 'ips' ) . " i ON i.id=e.ip_id
		 WHERE e.event_type='break_glass_access'
		 ORDER BY e.id DESC LIMIT 1"
	) : null;
	?>
<div class="wrap stp-wrap">
<h1>⚙️ SecureTrack Settings</h1>

<?php if ( stp_enforcement_paused() ): ?>
	<div class="notice notice-warning" style="border-left-width:6px">
		<p><strong>Emergency monitor-only mode is active.</strong> SecureTrack is still logging, but active denials and auto-blocking are paused so the public site and admin recovery path stay reachable.</p>
	</div>
<?php endif; ?>

<div class="stp-an-card" style="margin:12px 0 18px">
  <h2 style="margin-top:0">Critical Hacking Doors</h2>
  <p class="stp-desc">SecureTrack scans common WordPress attack surfaces and lets you harden them manually. Open doors are not always bugs, but they should be intentional.</p>
  <div class="stp-posture-summary">
    <strong>Security Grade <?php echo esc_html( stp_security_grade( $posture['score'] ) ); ?></strong>
    <span><?php echo (int) $posture['score']; ?>/100 posture score</span>
    <span><?php echo (int) $posture['open']; ?> open door<?php echo (int) $posture['open'] === 1 ? '' : 's'; ?></span>
  </div>
  <div class="stp-door-grid">
  <?php foreach ( $posture['items'] as $door ): ?>
    <div class="stp-door <?php echo $door['open'] ? 'stp-door-open' : 'stp-door-safe'; ?>">
      <strong><?php echo $door['open'] ? 'Open' : 'Protected'; ?>: <?php echo esc_html( $door['label'] ); ?></strong>
      <p><?php echo esc_html( $door['why'] ); ?></p>
      <small><?php echo esc_html( $door['fix'] ); ?></small>
      <?php if ( ! empty( $door['source'] ) ): ?><br><small><strong>Status:</strong> <?php echo esc_html( $door['source'] ); ?></small><?php endif; ?>
      <?php if ( ! empty( $door['setting_key'] ) ): ?>
        <p style="margin:10px 0 0">
          <?php if ( $door['open'] ): ?>
            <a class="button button-small button-primary" href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=harden&door=' . rawurlencode( $door['setting_key'] ), 'stp_do' ) ); ?>">Fix now</a>
          <?php elseif ( ! empty( $door['setting_enabled'] ) ): ?>
            <?php
            $off_label = 'Turn off SecureTrack control';
            if ( $door['setting_key'] === 'author_public_slugs' ) $off_label = 'Turn off safe author slugs';
            elseif ( $door['setting_key'] === 'harden_author_archives' ) $off_label = 'Turn off author archive redirect';
            ?>
            <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=unharden&door=' . rawurlencode( $door['setting_key'] ), 'stp_do' ) ); ?>" onclick="return confirm('Re-open this hardening control?')"><?php echo esc_html( $off_label ); ?></a>
          <?php else: ?>
            <small>Managed outside this SecureTrack toggle.</small>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=stp-settings' ) ); ?>">
  <?php wp_nonce_field( 'stp_settings_save', 'stp_settings_nonce' ); ?>
  <table class="form-table stp-stbl">

    <tr>
      <th scope="row">Risk Thresholds</th>
      <td>
        🔴 Red flag at score: <input type="number" name="red_threshold"    value="<?php echo esc_attr($s['red_threshold']); ?>"   min="1" max="100" style="width:60px"> &nbsp;
        🟡 Yellow flag at:    <input type="number" name="yellow_threshold" value="<?php echo esc_attr($s['yellow_threshold']); ?>" min="1" max="100" style="width:60px">
        <p class="description">Events scoring above these values (0–100) get the respective flag. Red events trigger alerts and are never auto-deleted.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Auto Data Trim</th>
      <td>
        Delete 🟢 green events after <input type="number" name="green_trim_days"  value="<?php echo esc_attr($s['green_trim_days']); ?>"  min="0" style="width:60px"> days<br>
        Delete 🟡 yellow events after <input type="number" name="yellow_trim_days" value="<?php echo esc_attr($s['yellow_trim_days']); ?>" min="0" style="width:60px"> days
        <p class="description">🔴 Red events are kept forever (until you delete them manually). 0 = never auto-delete.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Brute Force</th>
      <td>
        <label><input type="checkbox" name="block_brute_force" <?php checked($s['block_brute_force']); ?>> Auto-block IPs on brute force</label><br>
        Block after <input type="number" name="brute_force_limit" value="<?php echo esc_attr($s['brute_force_limit']); ?>" min="3" style="width:60px"> failed logins within 1 hour
      </td>
    </tr>

    <tr>
      <th scope="row">Aegis Adaptive WAF</th>
      <td>
        <label><input type="checkbox" name="emergency_safe_mode" <?php checked($s['emergency_safe_mode']); ?>> Emergency monitor-only mode</label><br>
        <label><input type="checkbox" name="adaptive_waf" <?php checked($s['adaptive_waf']); ?>> Enable normalized semantic payload detection</label><br>
        Block request at WAF score <input type="number" name="waf_block_score" value="<?php echo esc_attr($s['waf_block_score']); ?>" min="50" max="100" style="width:70px"><br>
        <label><input type="checkbox" name="honeypot_enabled" <?php checked($s['honeypot_enabled']); ?>> Inject dynamic hidden honeypot endpoint</label><br>
        <label><input type="checkbox" name="tarpit_enabled" <?php checked($s['tarpit_enabled']); ?>> Tarpit severe bots instead of instantly closing the response</label>
        <p class="description">Local-only detection for obfuscated SQLi, XSS, PHP execution payloads, traversal, encoded payloads, and bots that scrape hidden links. Emergency monitor-only mode keeps logging but pauses active denials while you investigate false positives.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Endpoint Rate Limits</th>
      <td>
        <label><input type="checkbox" name="endpoint_rate_limits" <?php checked($s['endpoint_rate_limits']); ?>> Enable advanced per-IP endpoint rate limiting</label><br>
        Login <input type="number" name="rl_login_per_min" value="<?php echo esc_attr($s['rl_login_per_min']); ?>" min="5" max="2000" style="width:70px"> / min &nbsp;
        XML-RPC <input type="number" name="rl_xmlrpc_per_min" value="<?php echo esc_attr($s['rl_xmlrpc_per_min']); ?>" min="5" max="2000" style="width:70px"> / min &nbsp;
        REST <input type="number" name="rl_rest_per_min" value="<?php echo esc_attr($s['rl_rest_per_min']); ?>" min="5" max="2000" style="width:70px"> / min &nbsp;
        Admin <input type="number" name="rl_admin_per_min" value="<?php echo esc_attr($s['rl_admin_per_min']); ?>" min="5" max="2000" style="width:70px"> / min &nbsp;
        Frontend <input type="number" name="rl_frontend_per_min" value="<?php echo esc_attr($s['rl_frontend_per_min']); ?>" min="5" max="2000" style="width:70px"> / min
        <p class="description">Disabled by default. Enable only after tuning limits for your host/CDN/proxy. Logged-in users, SecureTrack AJAX, cron, WP-CLI, and active break-glass recovery remain exempt.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">SecureTrack v2 Site Brain</th>
      <td>
        <label><input type="checkbox" name="v2_site_brain" <?php checked($s['v2_site_brain']); ?>> Enable local self-learning Site Brain</label><br>
        Uncertain score window:
        <input type="number" name="v2_uncertain_low" value="<?php echo esc_attr( $s['v2_uncertain_low'] ); ?>" min="1" max="95" style="width:70px">
        to
        <input type="number" name="v2_uncertain_high" value="<?php echo esc_attr( $s['v2_uncertain_high'] ); ?>" min="2" max="99" style="width:70px"><br>
        <input type="hidden" name="v2_ai_provider" value="<?php echo esc_attr( $s['v2_ai_provider'] ); ?>">
        <input type="hidden" name="v2_ai_model" value="<?php echo esc_attr( $s['v2_ai_model'] ); ?>">
        <input type="hidden" name="v2_ai_mode" value="<?php echo esc_attr( $s['v2_ai_mode'] ); ?>">
        <input type="hidden" name="v2_ai_batch_mins" value="<?php echo esc_attr( $s['v2_ai_batch_mins'] ); ?>">
        <input type="hidden" name="v2_auto_block_local" value="<?php echo ! empty( $s['v2_auto_block_local'] ) ? '1' : ''; ?>">
        <input type="hidden" name="v2_share_patterns" value="<?php echo ! empty( $s['v2_share_patterns'] ) ? '1' : ''; ?>">
        <p><strong>AI provider settings live in Kiwe &gt; AI.</strong> SecureTrack still owns local Site Brain learning and security enforcement. Redacted security context uses Companion consent/scopes, and optional cloud review uses the shared Native AI provider/key when supported; there is no separate SecureTrack API key field.</p>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=kiwe-ai' ) ); ?>" class="button button-small">Open Kiwe AI settings</a></p>
        <strong>AI status:</strong>
        <?php if ( ! empty( $ai_status['connected'] ) && ( $ai_status['provider'] ?? '' ) === ( $s['v2_ai_provider'] ?? '' ) ): ?>
          <span style="color:#059669;font-weight:700">Connected</span>
        <?php elseif ( empty( $s['v2_ai_key'] ) || ( $s['v2_ai_provider'] ?? 'none' ) === 'none' ): ?>
          <span style="color:#64748b;font-weight:700">Not configured</span>
        <?php else: ?>
          <span style="color:#d97706;font-weight:700">Key stored, not verified</span>
        <?php endif; ?>
        <small><?php echo ! empty( $ai_status['message'] ) ? esc_html( ' - ' . $ai_status['message'] . ' (' . ( $ai_status['updated_at'] ?? '' ) . ')' ) : ''; ?></small>
        <a href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=testai', 'stp_do' ) ); ?>" class="button button-small">Test AI Connection</a><br>
        <p class="description">Batch/realtime review mode, local auto-block recommendation policy, and future pattern-sharing consent are controlled from Kiwe &gt; AI. Provider/model/API-key authority comes from the shared Native AI settings.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Hardening Toggles</th>
      <td>
        <label><input type="checkbox" name="harden_xmlrpc" <?php checked($s['harden_xmlrpc']); ?>> Disable XML-RPC endpoint</label><br>
        <label><input type="checkbox" name="harden_rest_users" <?php checked($s['harden_rest_users']); ?>> Block anonymous REST user listing</label><br>
        <label><input type="checkbox" name="harden_author_archives" <?php checked($s['harden_author_archives']); ?>> Block anonymous author archive enumeration</label><br>
        <label><input type="checkbox" name="author_public_slugs" <?php checked($s['author_public_slugs']); ?>> When author archives are public, use safe author slugs that do not expose login/user nicename</label><br>
        <label><input type="checkbox" name="harden_file_editor" <?php checked($s['harden_file_editor']); ?>> Disable theme/plugin file editor</label><br>
        <label><input type="checkbox" name="harden_wp_generator" <?php checked($s['harden_wp_generator']); ?>> Hide WordPress generator/version output</label><br>
        <label><input type="checkbox" name="harden_security_headers" <?php checked($s['harden_security_headers']); ?>> Send baseline browser security headers</label><br>
        <label><input type="checkbox" name="csp_enabled" <?php checked($s['csp_enabled']); ?>> Send Content Security Policy header</label><br>
        <label style="margin-left:22px"><input type="checkbox" name="csp_report_only" <?php checked($s['csp_report_only']); ?>> Report-only mode for CSP</label><br>
        <label style="margin-left:22px">CSP report URI <input type="url" name="csp_report_uri" value="<?php echo esc_attr($s['csp_report_uri']); ?>" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/csp-report' ) ); ?>"></label><br>
        <small style="margin-left:22px" class="description">CSP report URI must be on this site; external report endpoints are ignored to avoid leaking page/resource structure.</small><br>
        <label><input type="checkbox" name="security_txt_enabled" <?php checked($s['security_txt_enabled']); ?>> Publish <code>/.well-known/security.txt</code> contact file</label><br>
        Auto-logout inactive users after <input type="number" name="idle_timeout_mins" value="<?php echo esc_attr($s['idle_timeout_mins']); ?>" min="0" max="1440" style="width:70px"> minutes <small>(0 = disabled)</small><br>
        <span style="display:inline-block;margin-top:6px">Apply auto-logout to roles:</span><br>
        <?php foreach ( wp_roles()->get_names() as $role_slug => $role_name ) : ?>
          <label style="display:inline-block;margin:4px 12px 0 0"><input type="checkbox" name="idle_timeout_roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, (array) ( $s['idle_timeout_roles'] ?? array() ), true ) ); ?>> <?php echo esc_html( translate_user_role( $role_name ) ); ?></label>
        <?php endforeach; ?><br>
        <small class="description">If no roles are selected, auto-logout does not run even if minutes are set.</small>
        <p class="description">For news sites, leave author archives unblocked and keep safe author slugs enabled so readers can browse an author's posts without exposing login-linked usernames.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Break Glass Login</th>
      <td>
        <code><?php echo esc_html( home_url( stp_break_glass_path() ) ); ?></code><br>
        Recovery slug: <input type="text" name="break_glass_slug" value="<?php echo esc_attr( $s['break_glass_slug'] ); ?>" class="regular-text" pattern="[a-z0-9-]{10,80}">
        <a href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=regen_break_glass', 'stp_do' ) ); ?>" class="button" onclick="return confirm('Regenerate the break glass login URL? The old URL will stop working immediately.')">Regenerate</a>
        <p class="description">Normal <code>wp-login.php</code> is blocked for banned IPs. This private recovery URL opens a 15-minute audited login window tied to the same IP and browser, and every access raises a critical alert.</p>
        <p class="description">Last access: <?php echo $last_break_glass ? esc_html( date_i18n( 'M j Y H:i', strtotime( $last_break_glass->created_at ) ) . ' from ' . $last_break_glass->ip_address ) : 'never'; ?></p>
      </td>
    </tr>

    <tr>
      <th scope="row">Adaptive Learning</th>
      <td>
        <label><input type="checkbox" name="adaptive_learning" <?php checked($s['adaptive_learning']); ?>> Increase risk when the same IP repeats similar suspicious behavior over time</label>
        <p class="description">This uses your own local event history. No cloud service or external reputation feed is required.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Dashboard Activity</th>
      <td>
        <label><input type="checkbox" name="track_admin_activity" <?php checked($s['track_admin_activity']); ?>> Track logged-in WordPress dashboard activity for forensic review</label>
        <p class="description">Records admin screens, user/product/order/post actions, plugins, themes, settings, media, and moderation activity. SecureTrack pages are skipped to avoid noise.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Threat Intelligence</th>
      <td>
        <label><input type="checkbox" name="subnet_intel" <?php checked($s['subnet_intel']); ?>> Group nearby IPv4 addresses into /24 subnet intelligence</label><br>
        Raise coordinated subnet alert when <input type="number" name="subnet_alert_at" value="<?php echo esc_attr($s['subnet_alert_at']); ?>" min="2" max="20" style="width:60px"> IPs from the same /24 attack within the analysis window<br><br>
        <label><input type="checkbox" name="chain_detection" <?php checked($s['chain_detection']); ?>> Detect behavior chains: visitor pivot, brute force then success, account takeover, coordinated subnet probes</label><br>
        <label><input type="checkbox" name="attack_graph" <?php checked($s['attack_graph']); ?>> Predict attack graph progression and temporarily harden sensitive endpoints</label><br>
        <label><input type="checkbox" name="behavioral_risk" <?php checked($s['behavioral_risk']); ?>> Enable self-learning user/admin behavioral-risk scoring</label><br>
        Analysis window: <input type="number" name="chain_window_mins" value="<?php echo esc_attr($s['chain_window_mins']); ?>" min="5" max="1440" style="width:70px"> minutes
        <p class="description">This is local learning from your own site traffic. It links weak signals into stronger alerts without sending data to an outside service.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Tracking</th>
      <td>
        <label><input type="checkbox" name="track_visitors" <?php checked($s['track_visitors']); ?>> Track visitor sessions</label><br>
        <label><input type="checkbox" name="track_pages"    <?php checked($s['track_pages']); ?>   > Track page navigation &amp; time on page (JS beacon)</label><br>
        <label><input type="checkbox" name="exclude_admin"  <?php checked($s['exclude_admin']); ?>  > Exclude logged-in admins from page-view tracking</label>
      </td>
    </tr>

    <tr>
      <th scope="row">Geolocation</th>
      <td>
        <label><input type="checkbox" name="geo_enabled" <?php checked($s['geo_enabled']); ?>> Resolve country/city in the background via ipwho.is over HTTPS</label>
        <p>Country blocklist: <input type="text" name="country_blocklist" value="<?php echo esc_attr($s['country_blocklist']); ?>" placeholder="CN,RU,KP" style="width:220px"> <small>comma or space separated ISO codes; applies after geo is known</small></p>
        <p>
          Login country policy:
          <select name="login_country_policy">
            <option value="off" <?php selected( $s['login_country_policy'], 'off' ); ?>>Off - monitor only</option>
            <option value="deny" <?php selected( $s['login_country_policy'], 'deny' ); ?>>Deny login outside allowed countries</option>
            <option value="ban" <?php selected( $s['login_country_policy'], 'ban' ); ?>>Deny login and ban IP outside allowed countries</option>
          </select>
          Allowed login countries:
          <input type="text" name="login_allowed_countries" value="<?php echo esc_attr( $s['login_allowed_countries'] ); ?>" placeholder="IN,US" style="width:160px">
        </p>
        <p class="description">Login country policy only enforces when the IP already has known geo data. Break glass remains available for emergency recovery.</p>
        <p class="description">Geo runs via WP-Cron every 5 minutes in small free-endpoint batches and sends visitor IP addresses to the external geo provider. Disable this if your privacy policy or jurisdiction does not allow external IP enrichment.</p>
      </td>
    </tr>

    <tr>
      <th scope="row">Email Alerts</th>
      <td>
        Send alerts to: <input type="email" name="alert_email" value="<?php echo esc_attr($s['alert_email']); ?>" style="width:280px"><br>
        <label><input type="checkbox" name="alert_on_red" <?php checked($s['alert_on_red']); ?>> Email on every 🔴 red-flag event</label>
      </td>
    </tr>

    <tr>
      <th scope="row">Webhook Alerts</th>
      <td>
        Endpoint URL: <input type="url" name="stp_webhook_url" value="" style="width:340px" placeholder="<?php echo esc_attr( stp_webhook_url() ? 'Webhook stored - leave blank to keep' : 'https://hooks.slack.com/services/...' ); ?>"><br>
        HMAC signing secret: <input type="password" name="stp_webhook_secret" value="" style="width:260px" placeholder="<?php echo esc_attr( stp_webhook_secret() ? 'Secret stored - leave blank to keep' : 'optional signing secret' ); ?>" autocomplete="new-password">
        <?php if ( stp_webhook_url() || stp_webhook_secret() ): ?><label style="margin-left:10px"><input type="checkbox" name="stp_webhook_clear"> Clear stored webhook</label><?php endif; ?>
        <p class="description">
          Fires a signed <code>application/json</code> POST on red-flag events, throttled by subject during attacks.<br>
          Verify authenticity with header <code>X-STP-Signature: sha256=HMAC</code> using your secret.
          Works with Slack, Discord, n8n, Zapier, or any webhook endpoint.
        </p>
      </td>
    </tr>

  </table>
  <p class="submit"><input type="submit" name="stp_save" class="button-primary" value="Save Settings"></p>
</form>

<hr>
<h2>🧹 Manual Data Management</h2>
<div class="stp-action-row">
  <form method="post" style="display:inline-block;margin:0 6px 6px 0" onsubmit="return confirm('Toggle SecureTrack emergency monitor-only mode?')">
    <?php wp_nonce_field( 'stp_do' ); ?>
    <input type="hidden" name="page" value="stp-settings">
    <input type="hidden" name="stp_do" value="<?php echo stp_enforcement_paused() ? 'safemode_off' : 'safemode_on'; ?>">
    <button type="submit" class="button <?php echo stp_enforcement_paused() ? 'button-primary' : ''; ?>">
      <?php echo stp_enforcement_paused() ? 'Resume Enforcement' : 'Pause Enforcement'; ?>
    </button>
  </form>
  <?php foreach ( array( 'gc' => '🟢 Clear Green Events', 'yc' => '🟡 Clear Yellow Events', 'unblock' => '🔓 Unblock All IPs' ) as $act => $label ): ?>
    <form method="post" style="display:inline-block;margin:0 6px 6px 0" onsubmit="return confirm('<?php echo esc_js( $label ); ?>?')">
      <?php wp_nonce_field( 'stp_do' ); ?>
      <input type="hidden" name="page" value="stp-settings">
      <input type="hidden" name="stp_do" value="<?php echo esc_attr( $act ); ?>">
      <button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
    </form>
  <?php endforeach; ?>
  <a href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=repairdb', 'stp_do' ) ); ?>"
     class="button button-primary">🛠 Repair Database</a> &nbsp;
  <a href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=probe', 'stp_do' ) ); ?>"
     class="button">🧪 Create Test Event</a> &nbsp;
  <a href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=geonow', 'stp_do' ) ); ?>"
     class="button">🌍 Fetch Geo Now</a> &nbsp;
  <form method="post" style="display:inline-block;margin:0 6px 6px 0" onsubmit="return confirm('Train Site Brain from existing SecureTrack events? This preserves your events, alerts, protections, IPs, sessions, and subnets.')">
    <?php wp_nonce_field( 'stp_do' ); ?>
    <input type="hidden" name="page" value="stp-settings">
    <input type="hidden" name="stp_do" value="trainbrain">
    <button type="submit" class="button button-primary">🧠 Train Site Brain</button>
  </form>
  <form method="post" style="display:inline-block;margin:0 6px 6px 0" onsubmit="return confirm('This will PERMANENTLY delete ALL SecureTrack data. Are you absolutely sure?')">
    <?php wp_nonce_field( 'stp_do' ); ?>
    <input type="hidden" name="page" value="stp-settings">
    <input type="hidden" name="stp_do" value="reset">
    <input type="hidden" name="confirmed" value="1">
    <button type="submit" class="button" style="color:#c00;border-color:#c00">⚠️ Reset All Data</button>
  </form>
</div>

<hr>
<h2>ℹ️ System Status</h2>
<table class="form-table stp-stbl"><tbody>
  <tr><th>Plugin Version</th><td><?php echo STP_VER; ?></td></tr>
  <tr><th>DB Schema</th><td><?php echo esc_html( get_option('stp_db_version','—') ); ?></td></tr>
  <tr><th>DB Tables Ready</th><td><?php echo stp_tables_ready() ? '<strong style="color:#059669">Yes</strong>' : '<strong style="color:#c00">No - click Repair Database</strong>'; ?></td></tr>
  <tr><th>Enforcement</th><td><?php echo stp_enforcement_paused() ? '<strong style="color:#d97706">Paused - monitor-only mode</strong>' : '<strong style="color:#059669">Active</strong>'; ?></td></tr>
  <tr><th>Tracking Settings</th><td><?php echo ! empty($s['track_visitors']) ? 'Visitor tracking ON' : '<strong style="color:#c00">Visitor tracking OFF</strong>'; ?> / <?php echo ! empty($s['track_pages']) ? 'Page timing ON' : '<strong style="color:#c00">Page timing OFF</strong>'; ?> / <?php echo empty($s['exclude_admin']) ? 'Admin page views tracked' : '<strong style="color:#d97706">Admin page views excluded</strong>'; ?></td></tr>
  <tr><th>Last Frontend Hook</th><td><code><?php echo esc_html( $diag['last_template_redirect'] ?? 'never seen' ); ?></code></td></tr>
  <tr><th>Last Footer Hook</th><td><code><?php echo esc_html( $diag['last_wp_footer'] ?? 'never seen' ); ?></code></td></tr>
  <tr><th>Last Log Attempt</th><td><code><?php echo esc_html( $diag['last_log_attempt'] ?? 'none' ); ?></code></td></tr>
  <tr><th>Last Insert</th><td><code><?php echo esc_html( $diag['last_insert'] ?? 'none' ); ?></code></td></tr>
  <tr><th>Last Skip Reason</th><td><code><?php echo esc_html( $diag['last_skip_reason'] ?? 'none' ); ?></code></td></tr>
  <tr><th>Last DB Error</th><td><code><?php echo esc_html( $diag['last_db_error'] ?? 'none' ); ?></code></td></tr>
  <tr><th>Audit Hash Chain</th><td><code><?php echo esc_html( stp_audit_chain_status( 200 ) ); ?></code></td></tr>
  <tr><th>Total Events</th><td><?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('events')) ); ?></td></tr>
  <tr><th>Total IPs Tracked</th><td><?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('ips')) ); ?></td></tr>
  <tr><th>Open Alerts</th><td><?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('alerts')." WHERE is_resolved=0") ); ?></td></tr>
  <tr><th>Tracked Subnets</th><td><?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('subnets')) ); ?> / <?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('subnets')." WHERE is_banned=1") ); ?> banned</td></tr>
  <tr><th>Site Brain</th><td><?php echo ! empty($s['v2_site_brain']) ? '<strong style="color:#059669">Enabled</strong>' : '<strong style="color:#d97706">Disabled</strong>'; ?> / <?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('brain')) ); ?> learned features / <?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('ai_queue')." WHERE status='pending'") ); ?> AI pending</td></tr>
  <tr><th>AI Connection</th><td><?php echo ! empty($ai_status['connected']) ? '<strong style="color:#059669">Connected</strong>' : '<strong style="color:#d97706">Not verified</strong>'; ?> / <?php echo esc_html( strtoupper( $s['v2_ai_provider'] ?? 'none' ) ); ?> / <?php echo esc_html( $s['v2_ai_mode'] === 'always' ? 'Always On' : 'Batch' ); ?> / <?php echo esc_html( $ai_status['message'] ?? 'No test run yet' ); ?></td></tr>
  <tr><th>IPs Pending Geo</th><td><?php echo number_format( (int)$wpdb->get_var("SELECT COUNT(*) FROM ".stp_t('ips')." WHERE geo_fetched=0") ); ?></td></tr>
  <tr><th>Cron: Data Cleanup</th><td>
    <?php $nc = wp_next_scheduled('stp_cron_cleanup'); echo $nc ? esc_html(date('M j H:i',$nc)) : '<strong style="color:#c00">NOT scheduled</strong>'; ?>
    &nbsp; <a href="<?php echo esc_url( wp_nonce_url( '?page=stp-settings&stp_do=reschedule', 'stp_do' ) ); ?>" class="button button-small">Re-schedule</a>
  </td></tr>
  <tr><th>Cron: Geo Fetch</th><td>
    <?php $ng = wp_next_scheduled('stp_cron_geo'); echo $ng ? esc_html(date('M j H:i',$ng)) : '<strong style="color:#c00">NOT scheduled</strong>'; ?>
  </td></tr>
  <tr><th>Cron: AI Queue</th><td>
    <?php $na = wp_next_scheduled('stp_cron_ai_queue'); echo $na ? esc_html(date('M j H:i',$na)) : '<strong style="color:#c00">NOT scheduled</strong>'; ?>
  </td></tr>
</tbody></table>

</div>
<?php
}


// ════════════════════════════════════════════════════════════════
//  ADMIN CSS
// ════════════════════════════════════════════════════════════════

function stp_admin_css() {
	return '
.stp-wrap{max-width:1580px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.stp-hdr{display:flex;align-items:center;gap:14px;padding:14px 20px;background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:10px;margin-bottom:18px}
.stp-hdr h1{color:#fff;margin:0;font-size:20px;font-weight:700}
.stp-tagline{color:#94a3b8;font-size:12px;flex:1}
.stp-tagline-inline{font-size:14px;color:#94a3b8;font-weight:400}
.stp-rbtn{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.25);padding:5px 14px;border-radius:6px;cursor:pointer;font-size:12px;transition:background .2s}
.stp-rbtn:hover{background:rgba(255,255,255,.22)}
/* stat cards */
.stp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:10px;margin-bottom:18px}
.stp-card{background:#fff;border-radius:8px;padding:12px 14px;box-shadow:0 1px 3px rgba(0,0,0,.08);display:flex;flex-direction:column;gap:4px;border-left:4px solid #e2e8f0;transition:box-shadow .2s}
.stp-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.12)}
.stp-card-blue{border-left-color:#3b82f6}.stp-card-red{border-left-color:#ef4444}.stp-card-yellow{border-left-color:#f59e0b}
.stp-card-purple{border-left-color:#8b5cf6}.stp-card-orange{border-left-color:#f97316}.stp-card-teal{border-left-color:#14b8a6}
.stp-card-green{border-left-color:#10b981}.stp-card-gray{border-left-color:#6b7280}
.stp-n{font-size:26px;font-weight:800;color:#0f172a;line-height:1}
.stp-l{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.6px}
.stp-posture-summary{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0 2px}.stp-posture-summary strong{background:#0f172a;color:#fff;border-radius:6px;padding:5px 10px}.stp-posture-summary span{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:5px 10px;color:#334155}
.stp-door-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-top:12px}.stp-door{border:1px solid #e2e8f0;border-radius:8px;padding:12px;background:#fff}.stp-door p{margin:6px 0;color:#475569}.stp-door small{color:#64748b}.stp-door-open{border-left:4px solid #ef4444;background:#fff7f7}.stp-door-safe{border-left:4px solid #10b981;background:#f7fffb}
/* filter bar */
.stp-bar{background:#f8fafc;border:1px solid #e2e8f0;padding:10px 14px;border-radius:8px;margin-bottom:14px}
.stp-bar form{display:flex;flex-wrap:wrap;gap:7px;align-items:center}
.stp-bar select,.stp-bar input[type=text],.stp-bar input[type=date]{padding:5px 8px;border:1px solid #cbd5e1;border-radius:5px;font-size:12.5px;height:30px}
/* table */
.stp-tw{border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow-x:auto;margin-bottom:8px}
.stp-t{width:100%;border-collapse:collapse;font-size:12.5px}
.stp-t th{background:#0f172a;color:#e2e8f0;padding:8px 11px;text-align:left;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.7px;white-space:nowrap}
.stp-t td{padding:8px 11px;border-bottom:1px solid #f1f5f9;vertical-align:middle;background:#fff}
.stp-t tr:last-child td{border-bottom:none}
.stp-r-red td{background:#fff5f5!important}
.stp-r-yellow td{background:#fffceb!important}
.stp-r-green td{background:#f0fdf4!important}
.stp-new-row td{box-shadow:inset 4px 0 0 #2563eb}
.stp-new-divider td{background:#dbeafe!important;color:#1d4ed8!important;font-weight:800;text-transform:uppercase;letter-spacing:.6px;font-size:10px;border-top:2px solid #93c5fd;border-bottom:1px solid #bfdbfe}
.stp-new-divider-green td{background:#dcfce7!important;color:#047857!important;border-top-color:#86efac;border-bottom-color:#bbf7d0}
.stp-old-divider td{background:#f8fafc!important;color:#64748b!important;font-weight:700;text-transform:uppercase;letter-spacing:.6px;font-size:10px}
.stp-new-summary{background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #2563eb;color:#1e3a8a;padding:10px 12px;border-radius:8px;margin:10px 0 14px}
.stp-new-summary-green{background:#ecfdf5;border-color:#a7f3d0;border-left-color:#10b981;color:#064e3b}
.stp-rev td{opacity:.5}
.stp-ip-blocked td{background:#fff0f0!important}
.stp-ip-trusted td{background:#f0fdf4!important}
/* cells */
.stp-fc{font-size:16px;text-align:center}
.stp-tc{white-space:nowrap;color:#475569}
.stp-sub2{color:#94a3b8;font-style:italic;font-size:11px}
.stp-ot{color:#64748b}.stp-url{color:#94a3b8}
.stp-vis{color:#94a3b8}
.stp-ip{text-decoration:none}.stp-ip code,.stp-ip{background:#f1f5f9;padding:2px 6px;border-radius:3px;font-size:11.5px;color:#0f172a}
.stp-ist{font-weight:600;font-size:11px}
.stp-ist-blocked{color:#dc2626}.stp-ist-trusted{color:#059669}
.stp-ist-monitor{color:#d97706}.stp-ist-unknown{color:#94a3b8}
.stp-email{color:#94a3b8}
/* risk scores */
.stp-sc{display:inline-block;padding:2px 9px;border-radius:10px;font-weight:700;font-size:12px}
.rr{background:#fee2e2;color:#dc2626}
.ry{background:#fef3c7;color:#d97706}
.rg{background:#d1fae5;color:#059669}
.stp-rr{color:#94a3b8;font-size:10.5px}
.stp-emp{text-align:center;padding:36px;color:#94a3b8}
/* action buttons */
.stp-ac{white-space:nowrap}
.stp-b{padding:3px 7px;border-radius:4px;border:1px solid;cursor:pointer;font-size:12px;margin:1px;background:#fff;line-height:1.4;transition:background .15s}
.stp-bg{border-color:#10b981;color:#10b981}.stp-bg:hover{background:#d1fae5}
.stp-bd{border-color:#ef4444;color:#ef4444}.stp-bd:hover{background:#fee2e2}
.stp-bb{border-color:#6b7280;color:#6b7280}.stp-bb:hover{background:#f1f5f9}
.stp-bt{border-color:#3b82f6;color:#3b82f6}.stp-bt:hover{background:#eff6ff}
.stp-disabled,.stp-disabled:hover{opacity:.72!important;cursor:not-allowed!important;background:#f1f5f9!important;color:#64748b!important;border-color:#cbd5e1!important}
/* pagination */
.stp-pg{display:flex;gap:5px;align-items:center;padding:12px;flex-wrap:wrap}
.stp-pb{padding:3px 9px;border:1px solid #e2e8f0;border-radius:4px;text-decoration:none;color:#334155;font-size:12px}
.stp-pb.on{background:#0f172a;color:#fff;border-color:#0f172a}
.stp-tot{color:#94a3b8;font-size:12px;margin-left:auto}
/* tags / badges */
.stp-tag{display:inline-block;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:600;vertical-align:middle}
.stp-tag-p{background:#fef3c7;color:#92400e}
.stp-tag-h{background:#ede9fe;color:#6d28d9}
.stp-tag-ok{background:#d1fae5;color:#065f46}
/* page list */
.stp-pl{margin:5px 0 3px 14px;padding:0;list-style:decimal}
.stp-pl li{padding:2px 0;font-size:11px;display:flex;gap:8px;align-items:baseline}
.stp-purl{color:#334155;word-break:break-all;flex:1}
.stp-pt{color:#94a3b8;white-space:nowrap}
/* settings */
.stp-stbl td,.stp-stbl th{padding:8px 10px;font-size:13px}
.stp-desc{color:#475569;margin-bottom:14px}
/* CSV export */
.stp-export-btn{background:#fff;border-color:#10b981!important;color:#10b981!important;font-weight:600;margin-left:4px}
.stp-export-btn:hover{background:#d1fae5!important}
';
}


// ════════════════════════════════════════════════════════════════
//  ADMIN JAVASCRIPT
// ════════════════════════════════════════════════════════════════

function stp_admin_js() {
	return '
jQuery(function($){

  var nonce   = (typeof stpCfg!=="undefined") ? stpCfg.nonce   : "";
  var ajaxurl = (typeof stpCfg!=="undefined") ? stpCfg.ajaxurl : "/wp-admin/admin-ajax.php";

  /* ── Core AJAX helper ──────────────────────────────────── */
  function stpPost(action,data,cb){
    $.post(ajaxurl,$.extend({action:action,nonce:nonce},data),function(r){
      if(r.success){ if(cb)cb(r.data); }
      else alert("SecureTrack error: "+(r.data||"unknown"));
    },"json");
  }

  /* ── Public API (called from inline onclick) ──────────── */
  window.stpBlock = function(btn,id){
    if(id===undefined){ id=btn; btn=null; }
    if(!confirm("Block this IP? All future page views from it will be flagged.")) return;
    if(btn) $(btn).prop("disabled",true).text("Blocking...");
    stpPost("stp_block_ip",{id:id},function(){
      if(btn) $(btn).prop("disabled",false).text("Unblock").removeClass("stp-disabled").attr("onclick","stpUnblock(this,"+id+")");
      else location.reload();
    });
  };

  window.stpUnblock = function(btn,id){
    if(id===undefined){ id=btn; btn=null; }
    if(!confirm("Unblock this IP and keep it monitored?")) return;
    if(btn) $(btn).prop("disabled",true).text("Unblocking...");
    stpPost("stp_unblock_ip",{id:id},function(){
      if(btn) $(btn).prop("disabled",false).text("Block").removeClass("stp-disabled").attr("onclick","stpBlock(this,"+id+")");
      else location.reload();
    });
  };

  window.stpBlockRange = function(btn,id){
    if(id===undefined){ id=btn; btn=null; }
    if(!confirm("Block all tracked IPs in the same /24 range?")) return;
    if(btn) $(btn).prop("disabled",true).text("Banning...");
    stpPost("stp_block_nearby_ips",{id:id},function(d){
      var msg="/24 Banned";
      if(d&&d.blocked!==undefined) msg="/24 Banned ("+d.blocked+")";
      if(btn&&d&&d.range) $(btn).prop("disabled",false).text("Unban /24").removeClass("stp-disabled").attr("onclick","stpUnbanSubnet(this,\""+d.range+"\")");
      else if(btn) $(btn).text(msg).addClass("stp-disabled");
      else { alert(msg); location.reload(); }
    });
  };

  window.stpBlockIpByAddress = function(btn,ip){
    if(ip===undefined){ ip=btn; btn=null; }
    if(!confirm("Block IP "+ip+"?")) return;
    if(btn) $(btn).prop("disabled",true).text("Blocking...");
    stpPost("stp_block_ip_address",{ip:ip},function(){
      if(btn){ $(btn).text("Blocked").addClass("stp-disabled"); }
      else { alert("Blocked: "+ip); location.reload(); }
    });
  };

  window.stpUnblockIpByAddress = function(btn,ip){
    if(ip===undefined){ ip=btn; btn=null; }
    if(!confirm("Unblock IP "+ip+" and keep it monitored?")) return;
    if(btn) $(btn).prop("disabled",true).text("Unblocking...");
    stpPost("stp_unblock_ip_address",{ip:ip},function(){
      if(btn){ $(btn).prop("disabled",false).text("Block IP").attr("onclick","stpBlockIpByAddress(this,\""+ip+"\")"); }
      else { alert("Unblocked: "+ip); location.reload(); }
    });
  };

  window.stpBanSubnet = function(btn,subnet){
    if(subnet===undefined){ subnet=btn; btn=null; }
    if(!confirm("Ban all IPs in "+subnet+"?")) return;
    if(btn) $(btn).prop("disabled",true).text("Banning...");
    stpPost("stp_ban_subnet",{subnet:subnet,reason:"Admin dashboard subnet ban"},function(d){
      var msg="/24 Banned";
      if(d&&d.blocked!==undefined) msg="/24 Banned ("+d.blocked+")";
      if(btn){ $(btn).text(msg).addClass("stp-disabled"); }
      else { alert("Subnet banned: "+subnet); location.reload(); }
    });
  };

  window.stpUnbanSubnet = function(btn,subnet){
    if(subnet===undefined){ subnet=btn; btn=null; }
    if(!confirm("Unban "+subnet+"?")) return;
    if(btn) $(btn).prop("disabled",true).text("Unbanning...");
    stpPost("stp_unban_subnet",{subnet:subnet},function(){
      if(btn) $(btn).prop("disabled",false).text("Ban /24").removeClass("stp-disabled").attr("onclick","");
      location.reload();
    });
  };

  window.stpResolveAlert = function(id){
    stpPost("stp_resolve_alert",{id:id,action_taken:"acknowledged"},function(){ location.reload(); });
  };

  window.stpTrust = function(btn,id){
    if(id===undefined){ id=btn; btn=null; }
    if(btn) $(btn).prop("disabled",true).text("Trusting...");
    stpPost("stp_trust_ip",{id:id},function(){
      if(btn) $(btn).text("Trusted").addClass("stp-disabled");
      else location.reload();
    });
  };

  window.stpDel = function(id){
    if(!confirm("Delete this event record?")) return;
    stpPost("stp_delete_event",{id:id},function(){
      var row=$("[data-eid="+id+"]");
      row.fadeOut(200,function(){ row.remove(); });
    });
  };

  window.stpDelIp = function(id){
    if(!confirm("Delete this IP and ALL its associated events?\nThis cannot be undone.")) return;
    stpPost("stp_delete_ip",{id:id},function(){ location.reload(); });
  };

  window.stpGreen = function(id,t){
    stpPost("stp_green_flag",{id:id,t:t},function(){
      if(t==="e"){
        var row=$("[data-eid="+id+"]");
        row.removeClass("stp-r-red stp-r-yellow").addClass("stp-r-green");
        row.find(".stp-fc").text("🟢");
      } else {
        location.reload();
      }
    });
  };

  /* ── Refresh stat cards ────────────────────────────────── */
  window.stpRefresh = function(){
    stpPost("stp_get_stats",{},function(d){
      if(d.events_today    !==undefined) $("#stp-ct .stp-n").text(d.events_today);
      if(d.red_today       !==undefined) $("#stp-cr .stp-n").text(d.red_today);
      if(d.blocked_ips     !==undefined) $("#stp-cb .stp-n").text(d.blocked_ips);
      if(d.active_sessions !==undefined) $("#stp-ca .stp-n").text(d.active_sessions);
    });
  };

  /* Auto-refresh stat cards every 60 s on the events page */
  if(nonce && $(".stp-grid").length){
    setInterval(window.stpRefresh, 60000);
  }

});
';
}

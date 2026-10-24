<?php
namespace DSA\Onboarding;

use DSA\Access\Workspace_Admin_Service;
use DSA\Access\Commerce_App_Service;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Resumable private setup over the existing context and native record owners. */
final class Design_Context_Journey_Service {
	public const PAGE = 'kiwe-design-context';
	private const CACHE = 'kiwe_design_context_progress_v1';
	private static ?array $evidence = null;
	public static function url(): string { return admin_url( 'admin.php?page=' . self::PAGE ); }
	public function register(): void {
		add_action( 'admin_post_kiwe_context_journey', [ $this, 'handle' ] );
		add_action( 'updated_option', [ $this, 'option_changed' ], 10, 1 );
		add_action( 'added_option', [ $this, 'option_changed' ], 10, 1 );
		add_action( 'deleted_option', [ $this, 'option_changed' ], 10, 1 );
		foreach ( [ 'save_post', 'deleted_post', 'edit_comment', 'transition_comment_status', 'deleted_comment', 'profile_update', 'deleted_user', 'set_user_role', 'kiwe_context_selection_changed' ] as $hook ) add_action( $hook, [ self::class, 'invalidate' ] );
		foreach ( [ 'added_post_meta','updated_post_meta','deleted_post_meta','added_comment_meta','updated_comment_meta','deleted_comment_meta','added_user_meta','updated_user_meta','deleted_user_meta' ] as $hook ) add_action( $hook, [ $this, 'meta_changed' ], 10, 3 );
		add_action( 'plugins_loaded', [ $this, 'register_store_hooks' ], 30 );
	}
	public function register_store_hooks(): void {
		if ( !\DSA\Apps\Store_App_Service::enabled() ) return;
		foreach ( [ 'woocommerce_shipping_zone_method_status_toggled','woocommerce_shipping_zone_method_added','woocommerce_shipping_zone_method_deleted','woocommerce_shipping_zone_saved','woocommerce_shipping_zone_deleted','woocommerce_tax_rate_added','woocommerce_tax_rate_updated','woocommerce_tax_rate_deleted' ] as $hook ) { add_action( $hook, [ self::class, 'forget_store_review' ] ); }
	}
	public static function invalidate(): void { self::$evidence = null; delete_transient( self::CACHE ); }
	public function option_changed( string $name ): void {
		if ( str_starts_with( $name, 'woocommerce_' ) && \DSA\Apps\Store_App_Service::enabled() ) self::forget_store_review();
		if ( in_array( $name, [ Design_Context_Profile_Service::OPTION_PROFILE, 'blogname','blogdescription','site_icon','active_plugins','kiwe_site_logo_id','kiwe_site_logo_inverse_id','kiwe_store_phone','kiwe_store_email', \DSA\Apps\Store_App_Service::OPTION, DSA_OPTION_SETTINGS ], true ) || str_starts_with( $name, 'woocommerce_' ) || str_starts_with( $name, 'theme_mods_' ) ) self::invalidate();
	}
	public static function forget_store_review(): void {
		$p = self::stored();
		if ( isset( $p['journey']['reviews']['store'] ) ) { unset( $p['journey']['reviews']['store'] ); update_option( Design_Context_Profile_Service::OPTION_PROFILE, $p, false ); }
		self::invalidate();
	}
	public function meta_changed( $meta_id, $object_id, string $key ): void {
		if ( in_array( $key, [ Context_Selection_Service::MEDIA_META, Context_Selection_Service::TESTIMONIAL_META, Design_Context_Profile_Service::USER_META_TEAM_MEMBER, Design_Context_Profile_Service::USER_META_TEAM_TITLE, '_thumbnail_id', '_price' ], true ) || str_ends_with( $key, '_capabilities' ) ) self::invalidate();
	}
	private static function stored(): array { $p = get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] ); return is_array( $p ) ? $p : []; }
	public static function feature_enabled( string $feature ): bool {
		$p = self::stored();
		if ( 'services' === $feature ) return array_key_exists( 'enabled', (array) ( $p['services'] ?? [] ) ) ? ! empty( $p['services']['enabled'] ) : ( ! empty( $p['services']['sourcePostType'] ) || ! empty( $p['services']['items'] ) );
		if ( 'team' !== $feature ) return false;
		if ( isset( $p['about']['team']['enabledByOwner'] ) ) return true === $p['about']['team']['enabledByOwner'];
		return self::evidence()['team']; // Compatibility: existing selected staff keep working.
	}
	/** Small cached existence evidence; never current(), full catalogs, MIN/MAX or provider work. */
	private static function evidence(): array {
		if ( null !== self::$evidence ) return self::$evidence;
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['pages'], $cached['articles'], $cached['navigation'], $cached['media'], $cached['team'], $cached['products'], $cached['testimonials'] ) ) return self::$evidence = $cached;
		global $wpdb;
		$woo = \DSA\Apps\Store_App_Service::enabled();
		$public_types = array_values( array_unique( array_merge( [ 'post','page' ], get_post_types( [ 'publicly_queryable'=>true ] ) ) ) );
		if ( ! $woo ) $public_types = array_values( array_diff( $public_types, [ 'product' ] ) );
		$slots = implode( ',', array_fill( 0, count( $public_types ), '%s' ) );
		$e = [
			'pages'=>(bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status IN ('draft','pending','publish','private') AND post_title<>'' LIMIT 1" ),
			'articles'=>(bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status IN ('draft','pending','publish','private') AND post_title<>'' LIMIT 1" ),
			'navigation'=>(bool) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} r ON r.object_id=p.ID JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE p.post_type='nav_menu_item' AND p.post_status='publish' AND t.taxonomy='nav_menu' LIMIT 1" ),
			'products'=>$woo && (bool) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('draft','pending','publish','private') AND post_title<>'' LIMIT 1" ),
			'media'=>(bool) $wpdb->get_var( $wpdb->prepare( "SELECT a.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} a ON a.ID=m.post_id LEFT JOIN {$wpdb->posts} p ON p.ID=a.post_parent WHERE m.meta_key=%s AND m.meta_value='1' AND a.post_type='attachment' AND a.post_status='inherit' AND a.post_password='' AND (a.post_parent=0 OR (p.post_status='publish' AND p.post_password='' AND p.post_type IN ($slots))) LIMIT 1", Context_Selection_Service::MEDIA_META, ...$public_types ) ),
			'testimonials'=>(bool) $wpdb->get_var( $wpdb->prepare( "SELECT c.comment_ID FROM {$wpdb->commentmeta} m JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE m.meta_key=%s AND m.meta_value='1' AND c.comment_approved='1' AND c.comment_type IN ('','comment','review') AND c.comment_content<>'' AND (c.comment_type<>'review' OR p.post_type='product') AND p.post_status='publish' AND p.post_password='' AND p.post_type IN ($slots) LIMIT 1", Context_Selection_Service::TESTIMONIAL_META, ...$public_types ) ),
			'team'=>false,
		];
		// Native selected membership, never all site accounts or login information.
		$ids = get_users( [ 'meta_key'=>Design_Context_Profile_Service::USER_META_TEAM_MEMBER, 'meta_value'=>'1', 'fields'=>'ID', 'number'=>1, 'count_total'=>false, 'capability__in'=>[ 'manage_options','edit_posts','edit_pages','edit_products','manage_woocommerce' ] ] );
		foreach ( $ids as $id ) if ( User_Profile_Service::eligible( (int) $id ) ) { $e['team'] = true; break; }
		if ( ! $wpdb->last_error ) set_transient( self::CACHE, $e, 300 );
		return self::$evidence = $e;
	}
	private static function has( $value ): bool { return is_scalar( $value ) && '' !== trim( wp_strip_all_tags( (string) $value ) ); }
	/** The complete canonical context families, with optional fields explained at their editing home. */
	public static function projection(): ?array {
		if ( ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_context' ) ) return null;
		$p = self::stored(); $e = self::evidence(); $prefs = (array) ( $p['journey'] ?? [] );
		$woo = ( new Design_Context_Profile_Service() )->commerce_available();
		$step = static fn( $id, $label, $detail, $url, $checks, $required = true, $mode = 'include' ): array => [ 'id'=>$id, 'label'=>$label, 'detail'=>$detail, 'url'=>$url, 'checks'=>$checks, 'required'=>$required, 'mode'=>$mode ];
		$mode = static fn( string $id, bool $present ): string => in_array( $prefs['choices'][ $id ] ?? '', [ 'include','later','off' ], true ) ? $prefs['choices'][ $id ] : ( $present ? 'include' : 'later' );
		$steps = [];
		$steps[] = $step( 'identity', 'Identity', 'Website name, main and dark logos, site icon, tagline and business type.', admin_url( 'admin.php?page=kiwe-identity' ), [ 'Website name'=>self::has( get_bloginfo( 'name' ) ), 'Main logo'=>Site_Identity_Service::attachment_id() > 0, 'Square site icon'=>(int) get_option( 'site_icon', 0 ) > 0 ] );
		$steps[] = $step( 'story', 'Story & audience', 'What you do, who it is for and the result you want. Add your story, values, founder, mission, vision and proof when useful.', admin_url( 'admin.php?page=kiwe-story' ), [ 'Business description'=>self::has( $p['identity']['description'] ?? '' ), 'Primary audience'=>self::has( $p['audience']['primary'] ?? '' ), 'Website goal'=>self::has( $p['seo']['primaryGoal'] ?? '' ) ] );
		$steps[] = $step( 'contact', 'Contact & location', 'Your public contact details, WhatsApp, social links, address, timezone and location preferences.', admin_url( 'admin.php?page=kiwe-contact' ), [ 'Public phone'=>self::has( Site_Identity_Service::store_phone() ), 'Public email'=>is_email( Site_Identity_Service::store_email() ) !== false ] );
		$brand = self::has( $p['brand']['tone'] ?? '' ) || self::has( $p['brand']['notes'] ?? '' ) || ! empty( $p['brand']['colors'] );
		$steps[] = $step( 'brand', 'Brand style', 'Choose your colors, tone and design notes, or let your designer propose the style.', admin_url( 'admin.php?page=kiwe-brand' ), [ 'Preferences or designer choice'=>$brand || ! empty( $prefs['designerStyle'] ) ] );
		$steps[] = $step( 'pages', 'Pages & website plan', 'Create pages and their briefs, then choose what to feature. Draft pages count; publishing is a separate decision.', admin_url( 'admin.php?page=kiwe-pages' ), [ 'At least one named page'=>$e['pages'] ] );
		$publication = 'publication' === ( $p['identity']['siteType'] ?? '' );
		$steps[] = $step( 'articles', 'Articles', 'Write articles in Work → Posts. Organize categories and tags there; drafts are useful content for your brief.', admin_url( 'admin.php?page=kiwe-posts' ), [ 'At least one named article'=>$e['articles'] ], $publication, $publication ? 'include' : $mode( 'articles', $e['articles'] ) );
		$steps[] = $step( 'navigation', 'Navigation & categories', 'Arrange visitor menus in Organize → Navigation. Reuse Organize → Categories for post, product, service and tag collections.', admin_url( 'admin.php?page=kiwe-navigation' ), [ 'A menu with a saved link'=>$e['navigation'] ], false, $mode( 'navigation', $e['navigation'] ) );
		$steps[] = $step( 'search', 'Search & sharing', 'Describe how customers search and what they should see when your website is shared. These details also guide your design brief.', admin_url( 'admin.php?page=kiwe-story' ), [ 'Homepage search description'=>self::has( $p['seo']['homepageDescription'] ?? '' ), 'Customer search intent'=>self::has( $p['seo']['searchIntent'] ?? '' ) ] );
		$service_enabled = self::feature_enabled( 'services' );
		$service_ready = false;
		if ( $service_enabled ) {
			$source = sanitize_key( (string) ( $p['services']['sourcePostType'] ?? '' ) );
			if ( $source && post_type_exists( $source ) ) { global $wpdb; $service_ready = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('draft','pending','publish','private') AND post_title<>'' AND (post_content<>'' OR post_excerpt<>'') LIMIT 1", $source ) ); }
			else foreach ( (array) ( $p['services']['items'] ?? [] ) as $item ) if ( self::has( $item['title'] ?? '' ) && ( self::has( $item['description'] ?? '' ) || self::has( $item['summary'] ?? '' ) ) ) { $service_ready = true; break; }
		}
		$steps[] = $step( 'services', 'Services', 'My business offers services. Manage descriptions, images, categories and supported service details in Work → Services.', admin_url( 'admin.php?page=kiwe-services' ), [ 'A service with a description'=>$service_ready ], false, $service_enabled ? 'include' : 'off' );
		$steps[] = $step( 'team', 'Team', 'My website has a public team. Choose staff and their public names, titles, biographies, portraits and links in Work → Team.', admin_url( 'admin.php?page=kiwe-team&team_view=members' ), [ 'An eligible selected team member'=>$e['team'] ], false, self::feature_enabled( 'team' ) ? 'include' : 'off' );
		$steps[] = $step( 'media', 'Media', 'Select additional images, video, audio and documents to guide your website design. Content and profile images are included automatically; only your explicit design selections count toward this step.', admin_url( 'admin.php?page=kiwe-context-library' ), [ 'A selected public file'=>$e['media'] ], false, $mode( 'media', $e['media'] ) );
		$steps[] = $step( 'testimonials', 'Testimonials', 'Select approved customer words in Daily → Feedback → Testimonials. It is fine to add these after launch.', admin_url( 'admin.php?page=kiwe-feedback&type=testimonial' ), [ 'An approved selected testimonial'=>$e['testimonials'] ], false, $mode( 'testimonials', $e['testimonials'] ) );
		if ( $woo ) {
			$store = current_user_can( 'edit_products' ) || current_user_can( 'edit_shop_orders' );
			$steps[] = $step( 'products', 'Products', 'Add products in Work → Products: names, descriptions, images, prices, stock, categories and tags. Drafts let you prepare before launch.', $store ? Commerce_App_Service::url( 'products' ) : '', [ 'At least one named product'=>$e['products'] ] );
			$checks = [ 'Store address'=>self::has( get_option( 'woocommerce_store_address', '' ) ) && self::has( get_option( 'woocommerce_store_city', '' ) ) && self::has( get_option( 'woocommerce_default_country', '' ) ), 'Store currency'=>self::has( get_option( 'woocommerce_currency', '' ) ) ];
			$steps[] = $step( 'store', 'Store setup', 'Review currency, units, selling countries, delivery, taxes and checkout policy in the existing Store spaces. Completion is setup evidence, not a payment or delivery test.', $store ? Commerce_App_Service::url( 'settings' ) : '', $checks + [ 'Store settings reviewed'=>! empty( $prefs['reviews']['store'] ) && hash_equals( self::store_fingerprint(), (string) $prefs['reviews']['store'] ) ] );
			$regulatory = self::has( $p['regulatory']['fssaiLicense'] ?? '' ) || self::has( $p['regulatory']['gstNumber'] ?? '' ) || self::has( $p['regulatory']['manufacturingAddress'] ?? '' );
			$steps[] = $step( 'regulatory', 'Business registrations', 'Add registration and manufacturing facts only when they apply to your business. Kiwe does not infer legal obligations.', admin_url( 'admin.php?page=kiwe-store-plan' ), [ 'Applicable business facts'=>$regulatory ], false, $mode( 'regulatory', $regulatory ) );
		}
		// Essentials form the journey; optional additions remain discoverable after them.
		$steps = array_merge( array_values( array_filter( $steps, static fn( $s ) => $s['required'] ) ), array_values( array_filter( $steps, static fn( $s ) => ! $s['required'] ) ) );
		$ready = 0; $total = 0; $later = 0; $next = null;
		foreach ( $steps as &$s ) {
			$s['deferred'] = ! empty( $prefs['deferred'][ $s['id'] ] ) || 'later' === $s['mode'];
			$s['included'] = $s['required'] || 'include' === $s['mode'];
			$s['ready'] = count( array_filter( $s['checks'] ) ); $s['total'] = count( $s['checks'] ); $s['complete'] = $s['ready'] === $s['total'];
			$s['status'] = ! $s['included'] ? ( 'later' === $s['mode'] ? 'For later' : 'Not needed' ) : ( $s['complete'] ? 'Complete' : ( $s['deferred'] ? 'For later · still required' : 'To do' ) );
			if ( $s['deferred'] && ! $s['complete'] ) ++$later;
			if ( $s['included'] ) { $ready += $s['ready']; $total += $s['total']; if ( ! $s['complete'] && ! $s['deferred'] && ! $next ) $next = $s['id']; }
		}
		unset( $s );
		if ( ! $next ) foreach ( $steps as $s ) if ( $s['included'] && ! $s['complete'] ) { $next = $s['id']; break; }
		return [ 'percent'=>(int) floor( 100 * $ready / max( 1, $total ) ), 'ready'=>$ready, 'total'=>$total, 'later'=>$later, 'next'=>$next, 'url'=>self::url(), 'resumeUrl'=>self::url() . ( $next ? '#context-' . $next : '' ), 'steps'=>$steps, 'expected'=>self::expected( $p ), 'designerStyle'=>! empty( $prefs['designerStyle'] ), 'woo'=>$woo ];
	}
	private static function store_fingerprint(): string {
		$values = [];
		foreach ( [ 'woocommerce_store_address','woocommerce_store_city','woocommerce_default_country','woocommerce_currency','woocommerce_weight_unit','woocommerce_dimension_unit','woocommerce_allowed_countries','woocommerce_specific_allowed_countries','woocommerce_ship_to_countries','woocommerce_calc_taxes','woocommerce_prices_include_tax','woocommerce_tax_classes','woocommerce_cod_settings','woocommerce_bacs_settings','woocommerce_cheque_settings' ] as $key ) $values[ $key ] = get_option( $key, null );
		return hash( 'sha256', wp_json_encode( $values ) );
	}
	private static function expected( array $p ): string { return hash( 'sha256', wp_json_encode( [ $p['journey'] ?? [], $p['services']['enabled'] ?? null, $p['about']['team']['enabledByOwner'] ?? null ] ) ); }
	/** Exact preference patch; never submits or rewrites the rest of the business profile. */
	public static function save( string $id, string $action, string $expected ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'kiwe_manage_context' ) ) return new \WP_Error( 'kiwe_context_forbidden', 'Design Context management access required.' );
		$p = self::stored();
		if ( ! hash_equals( self::expected( $p ), $expected ) ) return new \WP_Error( 'kiwe_context_stale', 'Your setup choices changed elsewhere. Reload and review them before saving.' );
		$view = self::projection(); $step = null; foreach ( $view['steps'] as $candidate ) if ( $id === $candidate['id'] ) $step = $candidate;
		if ( ! $step || ! in_array( $action, [ 'include','later','off','resume','designer','review' ], true ) || ( $step['required'] && in_array( $action, [ 'include','off' ], true ) ) || ( in_array( $id, [ 'services','team' ], true ) && 'later' === $action ) || ( 'designer' === $action && 'brand' !== $id ) || ( 'review' === $action && 'store' !== $id ) ) return new \WP_Error( 'kiwe_context_choice', 'Choose an available setup action.' );
		if ( 'review' === $action && ! current_user_can( 'edit_products' ) && ! current_user_can( 'edit_shop_orders' ) ) return new \WP_Error( 'kiwe_context_forbidden', 'Store access is required to review this step.' );
		if ( 'team' === $id && ! current_user_can( 'kiwe_manage_team' ) ) return new \WP_Error( 'kiwe_context_forbidden', 'Team management access is required to change public team visibility.' );
		if ( 'services' === $id ) $p['services']['enabled'] = 'include' === $action || ( 'off' !== $action && self::feature_enabled( 'services' ) );
		elseif ( 'team' === $id ) $p['about']['team']['enabledByOwner'] = 'include' === $action || ( 'off' !== $action && self::feature_enabled( 'team' ) );
		elseif ( ! $step['required'] && in_array( $action, [ 'include','later','off' ], true ) ) $p['journey']['choices'][ $id ] = $action;
		if ( 'designer' === $action ) $p['journey']['designerStyle'] = true;
		if ( 'review' === $action ) $p['journey']['reviews']['store'] = self::store_fingerprint();
		$p['journey']['deferred'][ $id ] = 'later' === $action;
		update_option( Design_Context_Profile_Service::OPTION_PROFILE, $p, false );
		if ( get_option( Design_Context_Profile_Service::OPTION_PROFILE, [] ) !== $p ) return new \WP_Error( 'kiwe_context_write', 'Your choice could not be confirmed. Reload before retrying.' );
		self::invalidate();
		return true;
	}
	public function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'kiwe_manage_context' ) ) wp_die( 'Design Context management access required.', '', [ 'response'=>403 ] );
		check_admin_referer( 'kiwe_context_journey' );
		$step = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		$result = self::save( $step, sanitize_key( wp_unslash( $_POST['choice'] ?? ( in_array( $step, [ 'services','team' ], true ) ? 'off' : '' ) ) ), sanitize_text_field( wp_unslash( $_POST['expected'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), '', [ 'response'=>409, 'back_link'=>true ] );
		wp_safe_redirect( add_query_arg( 'saved', '1', self::url() ) . '#context-' . sanitize_key( wp_unslash( $_POST['step'] ?? '' ) ) ); exit;
	}
	public static function progress_markup(): void {
		$v = self::projection(); if ( ! $v ) return;
		echo '<aside class="kiwe-context-progress" aria-label="Design Context progress"><div><a href="' . esc_url( $v['url'] ) . '">Design Context <strong>' . esc_html( $v['percent'] ) . '%</strong></a><span>' . esc_html( $v['ready'] . ' of ' . $v['total'] . ' setup checks' . ( $v['later'] ? ' · ' . $v['later'] . ' for later' : '' ) ) . '</span></div><progress max="100" value="' . esc_attr( $v['percent'] ) . '" aria-label="Design Context completion">' . esc_html( $v['percent'] ) . '%</progress><a class="kiwe-context-resume" href="' . esc_url( $v['resumeUrl'] ) . '">' . esc_html( $v['next'] ? 'Resume setup ↗' : 'Review context ↗' ) . '</a></aside>';
	}
	private function form( array $v, array $step, string $choice, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="kiwe_context_journey"><input type="hidden" name="step" value="' . esc_attr( $step['id'] ) . '"><input type="hidden" name="expected" value="' . esc_attr( $v['expected'] ) . '">'; wp_nonce_field( 'kiwe_context_journey' );
		echo '<button type="submit" name="choice" value="' . esc_attr( $choice ) . '">' . esc_html( $label ) . '</button></form>';
	}
	public function render(): void {
		$v = self::projection(); if ( ! $v ) return;
		echo '<div class="kiwe-context-journey"><p class="kiwe-shell-eyebrow">YOUR WEBSITE, TOGETHER</p><h1>Your Design Context.</h1><p class="kiwe-context-intro">Build your brief one step at a time. Your saved answers and selected content stay connected to the website. Resume here from any workspace.</p>';
		if ( '1' === ( $_GET['saved'] ?? '' ) ) echo '<p role="status" class="kiwe-context-notice">Your setup choice is saved. Enabled Services and Team appear in Home → Work; use their card below to open the existing space.</p>';
		echo '<p class="kiwe-context-note">100% means all required checks and the additions you chose are ready. Optional details and additions saved for later remain available below. This is brief completion; it does not certify launch, SEO rankings or checkout.</p><nav class="kiwe-context-stage-nav" aria-label="Design Context sections">';
		foreach ( $v['steps'] as $s ) echo '<a href="#context-' . esc_attr( $s['id'] ) . '">' . esc_html( $s['label'] ) . '</a>';
		echo '</nav><div class="kiwe-context-steps">';
		foreach ( $v['steps'] as $s ) {
			echo '<section class="kiwe-context-step" id="context-' . esc_attr( $s['id'] ) . '" aria-labelledby="context-title-' . esc_attr( $s['id'] ) . '"><p class="kiwe-context-meta">' . esc_html( $s['required'] ? 'REQUIRED' : 'OPTIONAL' ) . ' · ' . esc_html( $s['status'] ) . '</p><h2 id="context-title-' . esc_attr( $s['id'] ) . '">' . esc_html( $s['label'] ) . '</h2><p>' . esc_html( $s['detail'] ) . '</p>';
			if ( in_array( $s['id'], [ 'services','team' ], true ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="kiwe-context-feature"><input type="hidden" name="action" value="kiwe_context_journey"><input type="hidden" name="step" value="' . esc_attr( $s['id'] ) . '"><input type="hidden" name="expected" value="' . esc_attr( $v['expected'] ) . '">'; wp_nonce_field( 'kiwe_context_journey' );
				echo '<label><input type="checkbox" role="switch" name="choice" value="include"' . checked( $s['included'], true, false ) . '> ' . esc_html( 'services' === $s['id'] ? 'My business offers services' : 'My website has a public team' ) . '</label><button type="submit">Save choice</button></form><p class="kiwe-context-hint">' . esc_html( $s['included'] ? 'Enabled in Home → Work. Turning this off keeps your saved records.' : 'Off. Existing records are kept and this does not reduce your progress.' ) . '</p>';
			}
			if ( $s['included'] ) { echo '<ul class="kiwe-context-checks">'; foreach ( $s['checks'] as $label=>$ok ) echo '<li><span aria-hidden="true">' . ( $ok ? '✓' : '○' ) . '</span> ' . esc_html( $label ) . '<span class="kiwe-context-check-state">' . ( $ok ? 'Ready' : 'To do' ) . '</span></li>'; echo '</ul>'; }
			echo '<div class="kiwe-context-actions">';
			if ( $s['url'] && ( $s['included'] || ! in_array( $s['id'], [ 'services','team' ], true ) ) ) echo '<a class="kiwe-context-open" href="' . esc_url( $s['url'] ) . '">Open ' . esc_html( $s['label'] ) . ' ↗</a>';
			elseif ( $s['included'] && ! $s['url'] ) echo '<p>Your site manager can complete this step with the required store access.</p>';
			if ( ! in_array( $s['id'], [ 'services','team' ], true ) ) {
				if ( ! $s['required'] && 'include' !== $s['mode'] ) $this->form( $v, $s, 'include', 'Add to my brief' );
				if ( ! $s['required'] && 'off' !== $s['mode'] ) $this->form( $v, $s, 'off', 'Not needed' );
				if ( ! $s['complete'] && ! $s['deferred'] ) $this->form( $v, $s, 'later', 'Do later' );
				if ( $s['required'] && $s['deferred'] ) $this->form( $v, $s, 'resume', 'Do now' );
			}
			if ( 'brand' === $s['id'] && ! $v['designerStyle'] ) $this->form( $v, $s, 'designer', 'Let my designer choose' );
			if ( 'store' === $s['id'] && $s['url'] ) $this->form( $v, $s, 'review', 'I have reviewed Store setup' );
			echo '</div></section>';
		}
		echo '</div></div>';
	}
}

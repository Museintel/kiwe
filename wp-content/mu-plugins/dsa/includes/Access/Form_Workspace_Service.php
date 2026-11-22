<?php
namespace DSA\Access;

use DSA\Utilities\Origin_Checker;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** One private inbox over distinct native Bricks and Core enquiry owners. */
final class Form_Workspace_Service {
	public const PAGE = 'kiwe-forms';
	public const PAGE_SIZE = 20;
	private array $form_settings = [];
	public static function url(): string { return admin_url( 'admin.php?page=' . self::PAGE ); }
	public static function allowed(): bool {
		return self::allowed_for( get_current_user_id() );
	}
	public static function allowed_for( int $id ): bool {
		$user = $id > 0 ? get_userdata( $id ) : null;
		if ( ! $user || ! user_can( $user, 'read' ) || Team_Workspace_Service::suspended( $id ) || ( is_multisite() && ! is_user_member_of_blog( $id ) ) ) return false;
		return Site_Owner_Service::owner_id() === $id || ( 'administrator' === WordPress_Role_Access_Service::role_for( $user ) && ( user_can( $user, 'kiwe_manage_context' ) || user_can( $user, 'manage_options' ) ) );
	}
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 36 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}
	public function menu(): void {
		if ( ! self::allowed() ) return;
		$hook = add_menu_page( __( 'Forms', 'dsa' ), __( 'Forms', 'dsa' ), 'read', self::PAGE, [ $this, 'document' ], 'dashicons-feedback', 36 );
		add_action( 'load-' . $hook, [ $this, 'document' ], 1 );
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/forms', [ 'methods'=>'GET', 'callback'=>[ $this, 'listing' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/forms/(?P<id>[1-9][0-9]*)', [ 'methods'=>'GET', 'callback'=>[ $this, 'detail' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/forms/enquiries/(?P<id>[1-9][0-9]*)', [ 'methods'=>'GET', 'callback'=>[ $this, 'enquiry_detail' ], 'permission_callback'=>[ $this, 'permission' ] ] );
		register_rest_route( 'dsa/v1', '/workspace/forms/enquiries/(?P<id>[1-9][0-9]*)/remove', [ 'methods'=>'POST', 'callback'=>[ $this, 'remove_enquiry' ], 'permission_callback'=>[ $this, 'remove_permission' ] ] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return self::allowed() && 'GET' === $request->get_method() && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && Origin_Checker::is_same_site_request();
	}
	private function fail( string $text, int $status = 400 ): \WP_REST_Response { return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_forms', $text ), $status ); }
	private function table(): string {
		$class = '\\Bricks\\Integrations\\Form\\Submission_Database';
		if ( ! class_exists( $class ) ) return '';
		$table = $class::get_table_name();
		global $wpdb;
		if ( ! is_string( $table ) || $table !== $wpdb->prefix . 'bricks_form_submissions' ) return '';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ? $table : '';
	}
	/** Count real native arrivals, including existing messages; IDs are a signed visit ceiling, not a count. */
	public static function activity( array $last = [] ): ?array {
		if ( ! self::allowed() ) return null;
		$table = ( new self() )->table();
		global $wpdb;
		if ( $wpdb->last_error ) return null;
		$epoch = 'bricks-forms-' . substr( hash( 'sha256', $wpdb->prefix . 'bricks_form_submissions' ), 0, 32 );
		$after = ( $last['epoch'] ?? '' ) === $epoch ? max( 0, (int) ( $last['ceiling'] ?? 0 ) ) : 0;
		$ceiling = 0; $count = 0;
		if ( $table ) {
			$ceiling = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM `{$table}`" );
			if ( $wpdb->last_error ) return null;
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE id>%d AND id<=%d", $after, $ceiling ) );
			if ( $wpdb->last_error ) return null;
		}
		$core = \DSA\Link_Hub\Enquiry_Store::activity( ( $last['epoch'] ?? '' ) === $epoch ? max( 0, (int) ( $last['coreCeiling'] ?? 0 ) ) : 0 );
		return $core ? [ 'epoch'=>$epoch, 'ceiling'=>max( 0, $ceiling ), 'coreCeiling'=>$core['ceiling'], 'count'=>max( 0, $count ) + $core['count'] ] : null;
	}
	private function settings( array $row ): array {
		$class = '\\Bricks\\Integrations\\Form\\Submission_Database';
		$key = (int) $row['post_id'] . ':' . (string) $row['form_id'];
		if ( ! isset( $this->form_settings[ $key ] ) ) {
			$value = $class::get_form_settings( (int) $row['post_id'], (string) $row['form_id'] );
			$this->form_settings[ $key ] = is_array( $value ) ? $value : [];
		}
		return $this->form_settings[ $key ];
	}
	private function label( array $row ): string {
		if ( is_string( $row['form_identity'] ?? null ) && strlen( $row['form_identity'] ) <= 2048 ) {
			$identity = \DSA\Bricks\Form_Identity_Service::stored_identity( [ \DSA\Bricks\Form_Identity_Service::META=>[ 'type'=>'kiwe-internal', 'value'=>$row['form_identity'] ] ], (int) $row['post_id'], (string) $row['form_id'] );
			if ( $identity && $identity['label'] ) return $identity['label'];
		}
		if ( ! empty( $row['form_data'] ) && strlen( $row['form_data'] ) <= 131072 ) {
			$data = json_decode( $row['form_data'], true );
			$identity = is_array( $data ) ? \DSA\Bricks\Form_Identity_Service::stored_identity( $data, (int) $row['post_id'], (string) $row['form_id'] ) : null;
			if ( $identity && $identity['label'] ) return $identity['label'];
		}
		$settings = $this->settings( $row );
		return \DSA\Bricks\Form_Identity_Service::identity( $settings, (int) $row['post_id'], (string) $row['form_id'] )['label'];
	}
	private function identity_column( string $alias = '' ): string {
		$column = $alias ? $alias . '.form_data' : 'form_data';
		return "LEFT(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID({$column}) THEN {$column} ELSE '{}' END, '$.\"_kiwe_form_identity_v1\".value')),2049) AS form_identity";
	}
	/** Presentation-only notification data. No enquiry answers or contact details enter notices. */
	public static function notification_record( int $id ): ?array {
		$self = new self(); $table = $self->table(); if ( ! $table || $id < 1 ) return null;
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,post_id,form_id,created_at,LEFT(form_data,131073) AS form_data FROM `{$table}` WHERE id=%d", $id ), ARRAY_A );
		return is_array( $row ) && ! $wpdb->last_error ? $self->item( $row ) : null;
	}
	private function item( array $row ): array {
		$post = get_post( (int) $row['post_id'] );
		return [ 'id'=>(int) $row['id'], 'formId'=>(string) $row['form_id'], 'title'=>$this->label( $row ), 'page'=>$post ? Task_Workspace_UI::text( $post->post_title, 180 ) : __( 'Original page unavailable', 'dsa' ), 'created'=>(string) $row['created_at'] ];
	}
	public function listing( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->fail( 'Forms are available only to the site owner.', 403 );
		$view = $request->get_query_params()['view'] ?? '';
		if ( 'all' === $view ) return $this->mixed_listing( $request );
		if ( '' !== $view ) return $this->fail( 'Refresh Forms before choosing this view.' );
		$table = $this->table();
		if ( ! $table ) return Task_Workspace_UI::response( [ 'available'=>false, 'items'=>[], 'forms'=>[], 'total'=>0, 'hasNext'=>false ] );
		$snapshots = Workspace_Activity_Service::snapshots();
		$ceiling = $snapshots['forms']['ceiling'] ?? 0;
		$q = $request->get_query_params(); $form = $q['form'] ?? ''; $before = $q['before'] ?? '';
		if ( ! is_string( $form ) || ( '' !== $form && ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $form ) ) || ( '' !== $before && ! Task_Workspace_UI::id( $before ) ) ) return $this->fail( 'Refresh Forms before choosing this view.' );
		global $wpdb;
		$where = 'id<=%d'; $args = [ $ceiling ];
		if ( $form && preg_match( '/^native-([0-9]+)-([a-zA-Z0-9_-]{1,64})$/D', $form, $match ) ) { $where .= ' AND post_id=%d AND form_id=%s'; $args[] = (int) $match[1]; $args[] = $match[2]; }
		elseif ( $form ) { $where .= ' AND form_id=%s'; $args[] = $form; } // Existing bookmarked views remain readable.
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $args ) : "SELECT COUNT(*) FROM `{$table}`" );
		if ( $wpdb->last_error ) return $this->fail( 'The native form count could not be read. Refresh to try again.', 503 );
		if ( $before ) { $where .= ' AND id<%d'; $args[] = (int) $before; }
		$sql = "SELECT id,post_id,form_id,created_at," . $this->identity_column() . " FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT 21";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) return $this->fail( 'The native form inbox could not be read. Refresh to try again.', 503 );
		$groups = $wpdb->get_results( $wpdb->prepare( "SELECT g.form_id,g.post_id,g.entries," . $this->identity_column( 's' ) . " FROM (SELECT form_id,post_id,COUNT(*) AS entries,MAX(id) AS latest_id FROM `{$table}` WHERE id<=%d GROUP BY form_id,post_id ORDER BY MAX(id) DESC LIMIT 101) g INNER JOIN `{$table}` s ON s.id=g.latest_id ORDER BY g.latest_id DESC", $ceiling ), ARRAY_A );
		if ( ! is_array( $rows ) || ! is_array( $groups ) || $wpdb->last_error ) return $this->fail( 'The native form inbox could not be read. Refresh to try again.', 503 );
		$items = array_map( [ $this, 'item' ], array_slice( $rows, 0, self::PAGE_SIZE ) );
		$forms = array_map( fn( $r ) => [ 'id'=>'native-' . (int) $r['post_id'] . '-' . $r['form_id'], 'label'=>$this->label( $r ), 'count'=>(int) $r['entries'] ], array_slice( $groups, 0, 100 ) );
		return Workspace_Activity_Service::attach_snapshots( Task_Workspace_UI::response( [ 'available'=>true, 'items'=>$items, 'forms'=>$forms, 'formsLimited'=>count( $groups ) > 100, 'total'=>$total, 'hasNext'=>count( $rows ) > self::PAGE_SIZE, 'nextBefore'=>$items ? end( $items )['id'] : 0 ] ), $snapshots );
	}
	/** Business fields only. Passwords, hidden values, file-system paths and provider tokens never project. */
	public static function public_fields( array $data, array $settings ): array {
		$labels = []; foreach ( (array) ( $settings['fields'] ?? [] ) as $f ) if ( is_array( $f ) ) $labels[ (string) ( $f['id'] ?? '' ) ] = Task_Workspace_UI::text( $f['label'] ?? '', 160 );
		$out = []; $types = [ 'text','email','tel','textarea','number','select','checkbox','radio','date','datetime-local','time','url','range' ];
		foreach ( array_slice( $data, 0, 100, true ) as $id=>$field ) {
			if ( ! is_array( $field ) || ! in_array( $field['type'] ?? '', $types, true ) || preg_match( '/password|passwd|secret|token|nonce|api.?key|authorization|credit.?card|card.?number|cvv/i', (string) $id . ' ' . ( $labels[ $id ] ?? '' ) ) ) continue;
			$value = $field['value'] ?? '';
			if ( is_array( $value ) ) { $value = array_slice( $value, 0, 30 ); if ( array_filter( $value, static fn( $v ) => ! is_scalar( $v ) ) ) continue; $value = implode( ', ', $value ); }
			if ( ! is_scalar( $value ) ) continue;
			$value = sanitize_textarea_field( (string) $value );
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 10000 ) : substr( $value, 0, 10000 );
			$out[] = [ 'label'=>( $labels[ $id ] ?? '' ) ?: Task_Workspace_UI::text( (string) $id, 160 ), 'value'=>$value ];
		}
		return $out;
	}
	public function detail( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->fail( 'Forms are available only to the site owner.', 403 );
		$table = $this->table(); $id = Task_Workspace_UI::id( $request['id'] );
		if ( ! $table || ! $id ) return $this->fail( 'This submission is unavailable.', 404 );
		$snapshots = Workspace_Activity_Service::snapshots();
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,post_id,form_id,created_at,LEFT(form_data,131073) AS form_data FROM `{$table}` WHERE id=%d LIMIT 1", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) return $this->fail( 'This submission is unavailable.', 404 );
		if ( strlen( (string) $row['form_data'] ) > 131072 ) return $this->fail( 'This large submission needs review in the native Bricks inbox.', 413 );
		$data = json_decode( (string) $row['form_data'], true );
		if ( ! is_array( $data ) ) return $this->fail( 'The saved submission could not be read.', 422 );
		$settings = $this->settings( $row );
		return Workspace_Activity_Service::attach_snapshots( Task_Workspace_UI::response( [ 'item'=>[ ...$this->item( $row ), 'fields'=>self::public_fields( $data, is_array( $settings ) ? $settings : [] ) ] ] ), $snapshots );
	}
	public function document(): void {
		if ( ! self::allowed() ) wp_die( 'Forms are available only to the site owner.', '', [ 'response'=>403 ] );
		Task_Workspace_UI::app_document( 'forms', 'Forms', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/forms' ), 'businessEmail'=>Site_Identity_Service::store_email(), 'contactUrl'=>admin_url( 'admin.php?page=kiwe-contact' ), 'focusId'=>Task_Workspace_UI::id( $_GET['submission'] ?? '' ) ?: 0, 'focusSource'=>( $_GET['submission_source'] ?? '' ) === 'links' ? 'links' : 'bricks' ] );
	}

	private static function cursor( $raw ) {
		if ( '' === $raw ) return null;
		if ( ! is_string( $raw ) || strlen( $raw ) > 256 || ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $raw ) ) return false;
		$value = json_decode( (string) base64_decode( strtr( $raw, '-_', '+/' ), true ), true );
		if ( ! is_array( $value ) || count( $value ) !== 3 || ! is_string( $value['created'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value['created'] ) || ! in_array( $value['source'] ?? '', [ 'bricks','links' ], true ) || ! is_int( $value['id'] ?? null ) || $value['id'] < 1 ) return false;
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value['created'], new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value['created'] ) return false;
		return $value;
	}

	/** Both native Bricks created_at and Core post_date_gmt are UTC. Source breaks same-second ties. */
	private static function before( ?array $cursor, string $source, string $date, string $id, array &$args ): string {
		if ( ! $cursor ) return '';
		$args[] = $cursor['created'];
		if ( $source === $cursor['source'] ) { $args[] = $cursor['created']; $args[] = $cursor['id']; return " AND ({$date}<%s OR ({$date}=%s AND {$id}<%d))"; }
		return ' AND ' . $date . ( 'bricks' === $source ? '<=' : '<' ) . '%s';
	}

	/** Versioned mixed projection; old numeric Bricks routes/filters retain their original meaning. */
	private function mixed_listing( \WP_REST_Request $request ): \WP_REST_Response {
		$q = $request->get_query_params(); $form = $q['form'] ?? ''; $cursor = self::cursor( $q['cursor'] ?? '' );
		if ( ! is_string( $form ) || ( '' !== $form && ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $form ) ) || false === $cursor ) return $this->fail( 'Refresh Forms before choosing this view.' );
		$snapshots = Workspace_Activity_Service::snapshots(); $snapshot = $snapshots['forms'] ?? null;
		if ( ! $snapshot || ! isset( $snapshot['coreCeiling'] ) ) return $this->fail( 'The form inbox could not be read. Refresh to try again.', 503 );
		global $wpdb; $table = $this->table(); $rows = []; $forms = []; $native_total = 0; $core_total = 0;
		if ( $wpdb->last_error ) return $this->fail( 'The native inbox is temporarily unavailable. Refresh to try again.', 503 );
		if ( $table ) {
			$base = 'id<=%d'; $base_args = [ $snapshot['ceiling'] ];
			if ( $form && \DSA\Link_Hub\Enquiry_Service::FORM !== $form ) {
				if ( preg_match( '/^native-([0-9]+)-([a-zA-Z0-9_-]{1,64})$/D', $form, $m ) ) { $base .= ' AND post_id=%d AND form_id=%s'; $base_args[] = (int) $m[1]; $base_args[] = $m[2]; }
				else { $base .= ' AND form_id=%s'; $base_args[] = $form; }
			}
			if ( \DSA\Link_Hub\Enquiry_Service::FORM !== $form ) {
				$native_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$base}", $base_args ) );
				if ( $wpdb->last_error ) return $this->fail( 'The native form count could not be read.', 503 );
				$args = $base_args; $where = $base . self::before( $cursor, 'bricks', 'created_at', 'id', $args );
				$native = $wpdb->get_results( $wpdb->prepare( "SELECT id,post_id,form_id,created_at," . $this->identity_column() . " FROM `{$table}` WHERE {$where} ORDER BY created_at DESC,id DESC LIMIT 21", $args ), ARRAY_A );
				if ( ! is_array( $native ) || $wpdb->last_error ) return $this->fail( 'The native form inbox could not be read.', 503 );
				foreach ( $native as $row ) $rows[] = [ ...$this->item( $row ), 'source'=>'bricks' ];
			}
			$groups = $wpdb->get_results( $wpdb->prepare( "SELECT g.form_id,g.post_id,g.entries," . $this->identity_column( 's' ) . " FROM (SELECT form_id,post_id,COUNT(*) AS entries,MAX(id) AS latest_id FROM `{$table}` WHERE id<=%d GROUP BY form_id,post_id ORDER BY MAX(id) DESC LIMIT 101) g INNER JOIN `{$table}` s ON s.id=g.latest_id ORDER BY g.latest_id DESC", $snapshot['ceiling'] ), ARRAY_A );
			if ( ! is_array( $groups ) || $wpdb->last_error ) return $this->fail( 'The native form groups could not be read.', 503 );
			$forms = array_map( fn( $r ) => [ 'id'=>'native-' . (int) $r['post_id'] . '-' . $r['form_id'], 'label'=>$this->label( $r ), 'count'=>(int) $r['entries'] ], $groups );
		}
		$core_where = "post_type=%s AND post_status='private' AND post_author=0 AND ID<=%d"; $core_args = [ \DSA\Link_Hub\Enquiry_Store::TYPE, $snapshot['coreCeiling'] ];
		$core_total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$core_where}", $core_args ) );
		if ( null === $core_total || $wpdb->last_error ) return $this->fail( 'The enquiry count could not be read.', 503 );
		if ( ! $form || \DSA\Link_Hub\Enquiry_Service::FORM === $form ) {
			$args = $core_args; $where = $core_where . self::before( $cursor, 'links', 'post_date_gmt', 'ID', $args );
			$core = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_date_gmt,LEFT(post_content,32769) AS post_content FROM {$wpdb->posts} WHERE {$where} ORDER BY post_date_gmt DESC,ID DESC LIMIT 21", $args ), ARRAY_A );
			if ( ! is_array( $core ) || $wpdb->last_error ) return $this->fail( 'The enquiry inbox could not be read.', 503 );
			foreach ( $core as $row ) $rows[] = \DSA\Link_Hub\Enquiry_Store::item( $row );
		}
		if ( (int) $core_total > 0 ) array_unshift( $forms, [ 'id'=>\DSA\Link_Hub\Enquiry_Service::FORM, 'label'=>__( 'Link enquiries', 'dsa' ), 'count'=>(int) $core_total ] );
		usort( $rows, static fn( $a, $b ) => strcmp( $b['created'], $a['created'] ) ?: strcmp( $b['source'], $a['source'] ) ?: $b['id'] <=> $a['id'] );
		$items = array_slice( $rows, 0, self::PAGE_SIZE ); $last = $items ? end( $items ) : null;
		$next = $last ? rtrim( strtr( base64_encode( wp_json_encode( array_intersect_key( $last, array_flip( [ 'created','source','id' ] ) ) ) ), '+/', '-_' ), '=' ) : '';
		$total = $native_total + ( ! $form || \DSA\Link_Hub\Enquiry_Service::FORM === $form ? (int) $core_total : 0 );
		return Workspace_Activity_Service::attach_snapshots( Task_Workspace_UI::response( [ 'available'=>true, 'items'=>$items, 'forms'=>array_slice( $forms, 0, 100 ), 'formsLimited'=>count( $forms ) > 100, 'total'=>$total, 'hasNext'=>count( $rows ) > self::PAGE_SIZE, 'nextCursor'=>$next ] ), $snapshots );
	}

	public function enquiry_detail( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->fail( 'Forms are available only to the site owner.', 403 );
		$id = Task_Workspace_UI::id( $request['id'] ); $record = $id ? \DSA\Link_Hub\Enquiry_Store::record( $id ) : null;
		if ( ! $record ) return $this->fail( 'This enquiry is unavailable.', 404 );
		$snapshots = Workspace_Activity_Service::snapshots(); $value = $record['value']; $subject = \DSA\Link_Hub\Enquiry_Service::subject( $value['subjectId'] );
		$item = [ 'id'=>$record['id'], 'source'=>'links', 'formId'=>\DSA\Link_Hub\Enquiry_Service::FORM, 'title'=>\DSA\Link_Hub\Enquiry_Store::title( $value['channel'] ), 'page'=>__( 'Links', 'dsa' ), 'created'=>$record['created'], 'verifiedContact'=>$value['verifiedContact'], 'fields'=>[
			[ 'label'=>__( 'Name', 'dsa' ), 'value'=>$value['name'] ],
			[ 'label'=>'email' === $value['contactKind'] ? __( 'Email', 'dsa' ) : __( 'Phone', 'dsa' ), 'value'=>$value['contact'] ],
			[ 'label'=>__( 'Message', 'dsa' ), 'value'=>$value['message'] ],
			[ 'label'=>__( 'About', 'dsa' ), 'value'=>$subject['title'] . "\n" . $subject['url'] ],
			[ 'label'=>__( 'Handoff', 'dsa' ), 'value'=>__( 'Saved in Kiwe. External sending and delivery are not confirmed.', 'dsa' ) ],
		] ];
		return Workspace_Activity_Service::attach_snapshots( Task_Workspace_UI::response( [ 'item'=>$item ] ), $snapshots );
	}

	public function remove_permission( \WP_REST_Request $request ): bool {
		return self::allowed() && 'POST' === $request->get_method() && (bool) wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && true === Origin_Checker::mutation_allowed( $request );
	}
	public function remove_enquiry( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->remove_permission( $request ) ) return $this->fail( 'This enquiry cannot be removed with this account.', 403 );
		$id = Task_Workspace_UI::id( $request['id'] ); if ( ! $id ) return $this->fail( 'This enquiry is unavailable.', 404 );
		$result = \DSA\Link_Hub\Enquiry_Store::remove( $id );
		if ( is_wp_error( $result ) ) return $this->fail( $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 503 ) );
		return Task_Workspace_UI::response( [ 'removed'=>true ] );
	}
}

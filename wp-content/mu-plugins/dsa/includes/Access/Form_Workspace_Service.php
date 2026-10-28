<?php
namespace DSA\Access;

use DSA\Utilities\Origin_Checker;
use DSA\Site\Site_Identity_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private projection of Bricks' original submissions. No parallel records or public read API. */
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
		if ( ! $table ) return null;
		global $wpdb;
		$epoch = 'bricks-forms-' . substr( hash( 'sha256', $table ), 0, 32 );
		$after = ( $last['epoch'] ?? '' ) === $epoch ? max( 0, (int) ( $last['ceiling'] ?? 0 ) ) : 0;
		$ceiling = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM `{$table}`" );
		if ( $wpdb->last_error ) return null;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE id>%d AND id<=%d", $after, $ceiling ) );
		if ( $wpdb->last_error ) return null;
		return [ 'epoch'=>$epoch, 'ceiling'=>max( 0, $ceiling ), 'count'=>max( 0, $count ) ];
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
		Task_Workspace_UI::app_document( 'forms', 'Forms', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/forms' ), 'businessEmail'=>Site_Identity_Service::store_email(), 'contactUrl'=>admin_url( 'admin.php?page=kiwe-contact' ), 'focusId'=>Task_Workspace_UI::id( $_GET['submission'] ?? '' ) ?: 0 ] );
	}
}

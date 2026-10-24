<?php
namespace DSA\Access;

use DSA\Utilities\Origin_Checker;

if ( ! defined( 'ABSPATH' ) ) exit;

/** A private presentation over the installed Bricks catalog and native WP posts. */
final class Template_Workspace_Service {
	public const PAGE = 'kiwe-templates';
	public const ROLE = 'kiwe_web_designer';
	public static function template_caps(): array {
		return [ 'kiwe_manage_templates','edit_kiwe_templates','edit_others_kiwe_templates','edit_published_kiwe_templates','edit_private_kiwe_templates','publish_kiwe_templates','read_private_kiwe_templates','create_kiwe_templates' ];
	}
	/** Native Trash/restore primitives belong to the final website controllers only. */
	public static function owner_template_caps(): array {
		return array_merge( self::template_caps(),[ 'delete_kiwe_templates','delete_others_kiwe_templates','delete_private_kiwe_templates','delete_published_kiwe_templates' ] );
	}
	public static function designer_caps(): array {
		return array_merge( self::template_caps(), [ 'read','upload_files','edit_pages','edit_others_pages','edit_published_pages','edit_private_pages','publish_pages','read_private_pages','kiwe_view_pages','bricks_full_access' ] );
	}
	public static function builder_actor( int $id = 0 ): bool {
		$id = $id ?: get_current_user_id();
		if ( $id < 1 ) return false;
		if ( Site_Owner_Service::is_owner( $id ) ) return true;
		$user = get_userdata( $id );
		return $user && array_intersect( [ 'administrator',self::ROLE ], (array) $user->roles ) && ! get_user_meta( $id, Team_Workspace_Service::SUSPENDED_META, true );
	}
	public static function available(): bool {
		return defined( 'BRICKS_DB_TEMPLATE_SLUG' ) && defined( 'BRICKS_DB_TEMPLATE_TYPE' ) && class_exists( '\Bricks\Setup' ) && class_exists( '\Bricks\Helpers' ) && post_type_exists( BRICKS_DB_TEMPLATE_SLUG );
	}
	public static function allowed(): bool { return is_user_logged_in() && self::builder_actor() && current_user_can( 'kiwe_manage_templates' ); }
	public static function url(): string { return admin_url( 'admin.php?page=' . self::PAGE ); }
	public static function types(): array {
		if ( ! self::available() ) return [];
		$types = \Bricks\Setup::get_control_options( 'templateTypes' );
		return is_array( $types ) ? array_filter( $types, static fn( $label, $key ) => is_string( $label ) && is_string( $key ) && preg_match( '/^[a-z0-9_]+$/D', $key ), ARRAY_FILTER_USE_BOTH ) : [];
	}
	public static function builder_url( int $id ): string {
		if ( ! self::allowed() || ! self::available() || ! in_array( get_post_type( $id ), [ 'page',BRICKS_DB_TEMPLATE_SLUG ], true ) || ! current_user_can( 'edit_post', $id ) ) return '';
		$url = (string) \Bricks\Helpers::get_builder_edit_link( $id );
		$target = wp_parse_url( $url ); $site = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $site ) || ! in_array( $target['scheme'] ?? '',[ 'https','http' ],true ) || strcasecmp( (string) ( $target['host'] ?? '' ),(string) ( $site['host'] ?? '' ) ) || (int) ( $target['port'] ?? 0 ) !== (int) ( $site['port'] ?? 0 ) ) return '';
		return esc_url_raw( $url );
	}
	public function register(): void {
		add_action( 'init', [ $this,'role' ], 4 );
		add_action( 'admin_menu', [ $this,'menu' ], 34 );
		if ( ! Team_App_Service::enabled() ) return;
		add_action( 'rest_api_init', [ $this,'routes' ] );
		if ( is_admin() ) add_action( 'load-toplevel_page_' . self::PAGE, [ $this,'document' ] );
	}
	public function role(): void {
		if ( ! get_role( self::ROLE ) ) add_role( self::ROLE, __( 'Web Designer','dsa' ), array_fill_keys( self::designer_caps(), true ) );
	}
	public function menu(): void {
		if ( self::allowed() ) add_menu_page( __( 'Templates','dsa' ), __( 'Templates','dsa' ), 'kiwe_manage_templates', self::PAGE, [ $this,'document' ], 'dashicons-layout',34 );
	}
	public function permission( \WP_REST_Request $request ): bool {
		return Team_App_Service::enabled() && self::allowed() && in_array( $request->get_method(), [ 'GET','POST' ], true ) && wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ),'wp_rest' ) && Origin_Checker::is_same_site_request() && ( 'GET' === $request->get_method() || true === Origin_Checker::mutation_allowed( $request ) );
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1','/workspace/templates', [ [ 'methods'=>'GET','callback'=>[ $this,'listing' ],'permission_callback'=>[ $this,'permission' ] ], [ 'methods'=>'POST','callback'=>[ $this,'create' ],'permission_callback'=>[ $this,'permission' ] ] ] );
		register_rest_route( 'dsa/v1','/workspace/templates/(?P<id>[1-9][0-9]*)', [ 'methods'=>'POST','callback'=>[ $this,'rename' ],'permission_callback'=>[ $this,'permission' ] ] );
	}
	private function fail( string $message, int $status = 400 ): \WP_REST_Response { return Task_Workspace_UI::failure( new \WP_Error( 'kiwe_templates', $message ),$status ); }
	private function proof( \WP_Post $post ): string { return hash( 'sha256', wp_json_encode( [ $post->ID,$post->post_title,$post->post_status,$post->post_modified_gmt,get_post_meta( $post->ID,BRICKS_DB_TEMPLATE_TYPE,true ) ] ) ); }
	private function item( \WP_Post $post ): array {
		$type = (string) get_post_meta( $post->ID,BRICKS_DB_TEMPLATE_TYPE,true );
		return [ 'id'=>(int) $post->ID,'title'=>$post->post_title,'type'=>$type,'typeLabel'=>self::types()[ $type ] ?? $type,'status'=>$post->post_status,'modified'=>$post->post_modified,'expected'=>$this->proof( $post ),'builderUrl'=>self::builder_url( (int) $post->ID ),'canTrash'=>current_user_can( 'delete_post', (int) $post->ID ),'canExport'=>class_exists( \DSA\Backup\Content_Archive_Service::class ) && \DSA\Backup\Content_Archive_Service::can_export( 'template',(int) $post->ID ) ];
	}
	public function listing( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) ) return $this->fail( 'Templates are unavailable for your account.',403 );
		if ( ! self::available() ) return Task_Workspace_UI::response( [ 'available'=>false,'items'=>[],'types'=>[],'hasNext'=>false ] );
		$query = $request->get_query_params(); $status = Task_Workspace_UI::text( $query['status'] ?? 'all',20 );
		$type = Task_Workspace_UI::text( $query['type'] ?? '',60 );
		$sort = $query['sort'] ?? 'modified'; $page = max( 1,min( 10000,absint( $query['page'] ?? 1 ) ) );
		$args = [ 'post_type'=>BRICKS_DB_TEMPLATE_SLUG,'posts_per_page'=>12,'paged'=>$page,'post_status'=>in_array( $status,[ 'draft','publish','pending','private','future' ],true ) ? $status : [ 'draft','publish','pending','private','future' ],'s'=>Task_Workspace_UI::text( $query['search'] ?? '',200 ),'search_columns'=>[ 'post_title' ],'orderby'=>[ in_array( $sort,[ 'title','date','modified' ],true ) ? $sort : 'modified' => 'title' === $sort ? 'ASC' : 'DESC','ID'=>'DESC' ] ];
		if ( isset( self::types()[ $type ] ) ) $args['meta_query'] = [ [ 'key'=>BRICKS_DB_TEMPLATE_TYPE,'value'=>$type ] ];
		$result = new \WP_Query( $args ); $items = [];
		foreach ( $result->posts as $post ) if ( current_user_can( 'edit_post',$post->ID ) ) $items[] = $this->item( $post );
		return Task_Workspace_UI::response( [ 'available'=>true,'items'=>$items,'types'=>self::types(),'hasNext'=>$page < $result->max_num_pages,'page'=>$page ] );
	}
	public function create( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) || ! self::available() || ! current_user_can( 'create_kiwe_templates' ) ) return $this->fail( 'Template creation is unavailable.',403 );
		$body = $request->get_json_params(); $title = Task_Workspace_UI::text( $body['title'] ?? '',240 ); $type = $body['type'] ?? '';
		if ( ! $title || ! is_string( $type ) || ! isset( self::types()[ $type ] ) ) return $this->fail( 'Enter a name and choose an available Bricks template type.' );
		$id = wp_insert_post( [ 'post_type'=>BRICKS_DB_TEMPLATE_SLUG,'post_title'=>wp_slash( $title ),'post_status'=>'draft','post_author'=>get_current_user_id(),'meta_input'=>[ BRICKS_DB_TEMPLATE_TYPE=>$type ] ],true );
		if ( is_wp_error( $id ) ) return Task_Workspace_UI::failure( $id,409 );
		$post = get_post( $id );
		if ( ! $post || $post->post_title !== $title || get_post_meta( $id,BRICKS_DB_TEMPLATE_TYPE,true ) !== $type ) return $this->fail( 'The draft could not be confirmed. Refresh Templates before retrying.',409 );
		return Task_Workspace_UI::response( [ 'item'=>$this->item( $post ) ],201 );
	}
	public function rename( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->permission( $request ) || ! self::available() ) return $this->fail( 'Template editing is unavailable.',403 );
		$id = absint( $request['id'] ); $post = get_post( $id ); $body = $request->get_json_params();
		if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type || ! current_user_can( 'edit_post',$id ) ) return $this->fail( 'This template is unavailable.',403 );
		$title = Task_Workspace_UI::text( $body['title'] ?? '',240 ); $expected = $body['expected'] ?? '';
		if ( ! $title || ! is_string( $expected ) || ! hash_equals( $this->proof( $post ),$expected ) ) return $this->fail( 'The template changed. Refresh and review before renaming.',409 );
		$result = wp_update_post( [ 'ID'=>$id,'post_title'=>wp_slash( $title ) ],true );
		if ( is_wp_error( $result ) ) return Task_Workspace_UI::failure( $result,409 );
		$post = get_post( $id ); if ( ! $post || $post->post_title !== $title ) return $this->fail( 'The name could not be confirmed. Refresh and review.',409 );
		return Task_Workspace_UI::response( [ 'item'=>$this->item( $post ) ] );
	}
	public function document(): void {
		if ( ! self::allowed() ) wp_die( 'Templates are unavailable for your account.','',[ 'response'=>403 ] );
		Task_Workspace_UI::app_document( 'templates','Templates',[ 'endpoint'=>rest_url( 'dsa/v1/workspace/templates' ),...Content_Archive_App_Service::config() ] );
	}
}

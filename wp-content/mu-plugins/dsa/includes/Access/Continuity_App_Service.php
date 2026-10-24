<?php
namespace DSA\Access;

use DSA\Onboarding\Design_Context_Profile_Service as Profile;
use DSA\Onboarding\Native_Service_Source_Service;
use DSA\Onboarding\Native_Service_Conversion_Service;
use DSA\Onboarding\Context_Selection_Service as Selection;
use DSA\Onboarding\SEO_Context_Service;
use DSA\Utilities\Origin_Checker;
use DSA\Site\Site_Identity_Service;
use DSA\Design\Seam_Token_Service;

if ( ! defined( 'ABSPATH' ) ) exit;

/** Private presentation adapters. Existing task services retain all write authority. */
final class Continuity_App_Service {
	public static function enabled(): bool { return defined( 'KIWE_REACT_WORKSPACE' ) && true === KIWE_REACT_WORKSPACE; }
	public function register(): void {
		if ( ! self::enabled() ) return;
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 35 );
		foreach ( [ 'kiwe-context-library','kiwe-story','kiwe-contact','kiwe-brand','kiwe-website-plan','kiwe-services','kiwe-store-plan','kiwe-timeline' ] as $slug ) add_action( 'load-toplevel_page_' . $slug, [ $this, 'document' ], 1 );
	}
	public static function url(): string { return admin_url( 'admin.php?page=kiwe-pages' ); }
	public function area_urls(): array {
		if ( ! current_user_can( 'kiwe_manage_context' ) ) return [];
		$out = []; foreach ( [ 'story','contact','brand','website-plan' ] as $area ) $out[ $area ] = admin_url( 'admin.php?page=kiwe-' . $area );
		if ( ( new Profile() )->commerce_available() ) $out['store'] = admin_url( 'admin.php?page=kiwe-store-plan' );
		return $out;
	}
	public function menu(): void {
		if ( current_user_can( 'kiwe_view_pages' ) || current_user_can( 'manage_options' ) ) {
			$hook = add_menu_page( 'Pages', 'Pages', current_user_can( 'manage_options' ) ? 'manage_options' : 'kiwe_view_pages', 'kiwe-pages', [ $this, 'document' ], 'dashicons-admin-page', 31 );
			add_action( 'load-' . $hook, [ $this, 'document' ], 1 );
		}
		if ( current_user_can( 'kiwe_manage_context' ) && ( new Profile() )->commerce_available() ) {
			$hook = add_menu_page( 'Store setup', 'Store setup', 'kiwe_manage_context', 'kiwe-store-plan', [ $this, 'document' ], 'dashicons-store', 32 );
			add_action( 'load-' . $hook, [ $this, 'document' ], 1 );
		}
		if ( current_user_can( 'moderate_comments' ) ) {
			$hook = add_menu_page( 'Feedback', 'Feedback', 'moderate_comments', 'kiwe-feedback', [ $this, 'document' ], 'dashicons-testimonial', 33 );
			add_action( 'load-' . $hook, [ $this, 'document' ], 1 );
		}
		if ( $this->timeline_allowed() ) {
			$hook = add_menu_page( 'Timeline', 'Timeline', 'read', 'kiwe-timeline', [ $this, 'document' ], 'dashicons-backup', 34 );
			add_action( 'load-' . $hook, [ $this, 'document' ], 1 );
		}
	}
	public function routes(): void {
		register_rest_route( 'dsa/v1', '/workspace/continuity/(?P<area>pages|library|feedback|story|contact|brand|website-plan|services|store|timeline)', [
			[ 'methods'=>'GET', 'callback'=>[ $this, 'read' ], 'permission_callback'=>[ $this, 'permission' ] ],
			[ 'methods'=>'POST', 'callback'=>[ $this, 'save' ], 'permission_callback'=>[ $this, 'permission' ] ],
		] );
	}
	public function permission( \WP_REST_Request $request ): bool {
		$area = $request->get_url_params()['area'] ?? '';
		$allowed = 'pages' === $area ? ( current_user_can( 'kiwe_view_pages' ) || current_user_can( 'manage_options' ) ) : ( 'feedback' === $area ? current_user_can( 'moderate_comments' ) : ( 'timeline' === $area ? $this->timeline_allowed() : current_user_can( 'kiwe_manage_context' ) ) );
		if ( ! self::enabled() || ! is_user_logged_in() || ! $allowed || ! in_array( $request->get_method(), [ 'GET','POST' ], true ) ) return false;
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! Origin_Checker::is_same_site_request() ) return false;
		return 'POST' !== $request->get_method() || true === Origin_Checker::mutation_allowed( $request );
	}
	private function response( $value ): \WP_REST_Response {
		return is_wp_error( $value ) ? Task_Workspace_UI::failure( $value, (int) ( $value->get_error_data()['status'] ?? 400 ) ) : Task_Workspace_UI::response( $value );
	}
	private function error( string $message, int $status = 400 ): \WP_Error { return new \WP_Error( 'kiwe_continuity', $message, [ 'status'=>$status ] ); }
	public static function fields(): array { return json_decode( (string) file_get_contents( __DIR__ . '/business-fields.json' ), true ) ?: []; }
	private function value( array $data, string $path ) { foreach ( explode( '.', $path ) as $key ) $data = is_array( $data ) ? ( $data[ $key ] ?? null ) : null; return $data; }
	private function assign( array &$data, string $path, $value ): void { $target =& $data; foreach ( explode( '.', $path ) as $key ) $target =& $target[ $key ]; $target = $value; }
	private function business( string $area ) {
		$schemas = self::fields(); if ( ! isset( $schemas[ $area ] ) || ( 'store' === $area && ! ( new Profile() )->commerce_available() ) ) return $this->error( 'This business area is unavailable.', 404 );
		$profile = ( new Profile() )->current(); $values = [];
		foreach ( $schemas[ $area ]['fields'] as $field ) $values[ $field['path'] ] = $this->value( $profile, $field['path'] );
		$out = [ 'area'=>$area, 'title'=>$schemas[ $area ]['title'], 'fields'=>$schemas[ $area ]['fields'], 'values'=>$values, 'expected'=>hash( 'sha256', wp_json_encode( $profile ) ), 'commerce'=>( new Profile() )->commerce_available(), 'canUpload'=>current_user_can( 'upload_files' ) ];
		// The existing card renderer follows the saved profile; hiding a card never clears its value.
		$out['fields'] = array_values( array_filter( $out['fields'], static function ( array $field ) use ( $values ): bool {
			$when = $field['hideWhen'] ?? null;
			return ! is_array( $when ) || ! array_key_exists( $when['path'], $values ) || $values[ $when['path'] ] !== $when['equals'];
		} ) );
		if ( ! $out['commerce'] && 'website-plan' === $area ) $out['fields'] = array_values( array_filter( $out['fields'], static fn( $field ) => 'contentPlan.highlightBestsellers' !== $field['path'] ) );
		if ( 'story' === $area ) {
			$out['people'] = [ 0=>'No linked account' ];
			foreach ( get_users( [ 'number'=>100, 'orderby'=>'display_name', 'order'=>'ASC' ] ) as $user ) if ( current_user_can( 'edit_user', (int) $user->ID ) ) $out['people'][ $user->ID ] = $user->display_name;
			$id = (int) ( $profile['about']['founder']['userId'] ?? 0 );
			if ( $id && ! isset( $out['people'][ $id ] ) ) { $user = get_userdata( $id ); if ( $user ) $out['people'][ $id ] = $user->display_name; }
			$id = (int) ( $profile['about']['founder']['imageId'] ?? 0 ); $out['images'] = $id ? [ $id=>wp_get_attachment_image_url( $id, 'medium' ) ] : [];
		}
		if ( 'services' === $area ) {
			$source = (string) ( $profile['services']['sourcePostType'] ?? '' ); $service = new Profile(); $object = $source ? get_post_type_object( $source ) : null;
			$can = ! $source || ( $object && current_user_can( $object->cap->edit_posts ) );
			foreach ( $profile['services']['items'] as $item ) if ( ! empty( $item['recordId'] ) && ! current_user_can( 'edit_post', (int) $item['recordId'] ) ) $can = false;
			$out['service'] = [ 'source'=>$source, 'canEdit'=>$can, 'hierarchical'=>$object && $object->hierarchical, 'taxonomies'=>$source ? $service->service_taxonomies( $source ) : [], 'fields'=>$source ? $service->service_meta_fields( $source ) : [], 'images'=>[] ];
			$out['service']['canStartNative'] = class_exists( Native_Service_Source_Service::class ) && Native_Service_Source_Service::can_activate();
			if ( class_exists( Native_Service_Conversion_Service::class ) ) {
				$conversion=new Native_Service_Conversion_Service();
				$out['service']['canConvertPlans']=$conversion->available();
				$out['service']['conversionRecovery']=$conversion->undo_summary();
			}
			$out['service']['canCreate'] = ! $source || ( $object && current_user_can( $object->cap->create_posts ) );
			$out['service']['canPublish'] = $object && current_user_can( $object->cap->publish_posts );
			$out['service']['categoriesUrl'] = $this->service_categories_url( $source, $object, $out['service']['taxonomies'] );
			foreach ( $profile['services']['items'] as $item ) if ( ! empty( $item['imageId'] ) ) $out['service']['images'][ $item['imageId'] ] = wp_get_attachment_image_url( (int) $item['imageId'], 'medium' );
			if ( ! $source ) {
				// A plan is Kiwe-owned context until a designer binds a native post type.
				// Give each plan an independent fingerprint so one editor never needs to
				// rewrite unrelated plans. Creation additionally checks the collection.
				$configuration = [ 'enabled'=>! empty( $profile['services']['enabled'] ), 'source'=>'', 'useForNavigation'=>! empty( $profile['services']['useForNavigation'] ) ];
				$out['planMode'] = true;
				$out['planExpected'] = hash( 'sha256', wp_json_encode( [ $configuration, $profile['services']['items'] ] ) );
				foreach ( $out['values']['services.items'] as &$item ) $item['expected'] = hash( 'sha256', wp_json_encode( [ $configuration, $item ] ) );
				unset( $item );
			}
		}
		return $out;
	}
	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'This workspace is unavailable.', 403 ) );
		$area = $request->get_url_params()['area'];
		if ( 'pages' === $area ) {
			if ( 'versions' === $request->get_param( 'operation' ) ) {
				$id = Task_Workspace_UI::id( $request->get_param( 'id' ) );
				return $this->response( $id ? ( new Page_Publishing_Service() )->revisions( $id ) : $this->error( 'Choose a page.' ) );
			}
			return $this->response( $this->pages( $request ) );
		}
		if ( 'library' === $area ) {
			if ( 'trash' === ( $body['operation'] ?? '' ) ) return $this->response( ( new Trash_Workspace_Service() )->mutate( $body ) );
			if ( 'attachment' === $request->get_param( 'operation' ) ) {
				$id = Task_Workspace_UI::id( $request->get_param( 'id' ) );
				return $this->response( $id ? $this->attachment( $id ) : $this->error( 'Choose a file.' ) );
			}
			return $this->response( $this->library( $request ) );
		}
		if ( 'feedback' === $area ) return $this->response( ( new Comment_Moderation_Service() )->listing( [ 'page'=>$request->get_param( 'page' ), 'search'=>$request->get_param( 'search' ), 'status'=>$request->get_param( 'status' ), 'type'=>$request->get_param( 'type' ), 'sort'=>$request->get_param( 'sort' ) ] ) );
		if ( 'timeline' === $area ) return $this->response( 'version' === $request->get_param( 'operation' ) ? $this->timeline_version( Task_Workspace_UI::id( $request->get_param( 'id' ) ) ) : $this->timeline( $request ) );
		if ( 'services' === $area ) {
			if ( 'service_version' === $request->get_param('operation') ) return $this->response((new Service_History_Service())->version(Task_Workspace_UI::id($request->get_param('id'))));
			if ( 'service_versions' === $request->get_param('operation') ) {
				$id=Task_Workspace_UI::id($request->get_param('id')); $current=$this->service_records(['id'=>$id]);
				$item=$current['values']['services.items'][0] ?? null;
				if (!$id || empty($current['recordMode']) || !$item || $item['recordId']!==$id || !is_string($request->get_param('editorExpected')) || !hash_equals($item['expected'],$request->get_param('editorExpected'))) return $this->response($this->error('This Service changed. Reload before opening its history.',409));
				return $this->response((new Service_History_Service())->revisions($id));
			}
			if ( 'service_conversion' === $request->get_param( 'operation' ) ) return $this->response( (new Native_Service_Conversion_Service())->preview() );
			if ( 'service_conversion_undo' === $request->get_param( 'operation' ) ) return $this->response( (new Native_Service_Conversion_Service())->undo_preview() );
			if ( 'records' === $request->get_param( 'mode' ) ) return $this->response( $this->service_records( [ 'page'=>$request->get_param( 'page' ), 'search'=>$request->get_param( 'search' ),'sort'=>$request->get_param('sort'),'status'=>$request->get_param('status') ] ) );
		}
		return $this->response( $this->business( $area ) );
	}
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || ! $this->permission( $request ) ) return $this->response( $this->error( 'This change is unavailable.', 403 ) );
		$body = $request->get_json_params(); if ( ! is_array( $body ) ) return $this->response( $this->error( 'Choose a change.' ) );
		$area = $request->get_url_params()['area'];
		if ( 'timeline' === $area ) return $this->response( $this->timeline_action( $body ) );
		if ( 'services' === $area && in_array($body['operation'] ?? '',['service_autosave','service_restore','service_discard'],true) ) {
			$operation=$body['operation']; $keys='service_autosave'===$operation ? ['operation','id','expected','autosaveExpected','title','content','excerpt'] : ['operation','id','revisionId','expected','service_restore'===$operation ? 'revisionExpected' : 'recoveryExpected'];
			if(array_diff(array_keys($body),$keys) || !is_int($body['id'] ?? null) || $body['id']<1 || ('service_autosave'!==$operation && (!is_int($body['revisionId'] ?? null) || $body['revisionId']<1))) return $this->response($this->error('Choose a current Service history action.'));
			$input=array_diff_key($body,array_fill_keys(['operation','id','revisionId'],true)); $history=new Service_History_Service();
			return $this->response('service_autosave'===$operation ? $history->autosave($body['id'],$input) : ('service_restore'===$operation ? $history->restore($body['id'],$body['revisionId'],$input) : $history->discard_autosave($body['id'],$body['revisionId'],$input)));
		}
		if ( 'services' === $area && 'service_record' === ( $body['operation'] ?? '' ) ) return $this->response( $this->save_service_record( $body ) );
		if ( 'services' === $area && 'service_plan' === ( $body['operation'] ?? '' ) ) return $this->response( $this->save_service_plan( $body ) );
		if ( 'services' === $area && in_array( $body['operation'] ?? '', [ 'convert_service_plans','undo_service_conversion' ],true ) ) {
			if ( array_diff( array_keys($body),[ 'operation','expected' ] ) || ! is_string($body['expected'] ?? null) ) return $this->response( $this->error('Open the current Service conversion review.') );
			$conversion=new Native_Service_Conversion_Service();
			return $this->response( $body['operation']==='convert_service_plans' ? $conversion->convert($body['expected']) : $conversion->undo($body['expected']) );
		}
		if ( 'services' === $area && 'activate_native_source' === ( $body['operation'] ?? '' ) ) {
			if ( array_diff( array_keys( $body ), [ 'operation','expected' ] ) || ! is_string( $body['expected'] ?? null ) ) return $this->response( $this->error( 'Choose the current service source.' ) );
			$result = ( new Native_Service_Source_Service() )->activate( $body['expected'] );
			return $this->response( is_wp_error( $result ) ? $result : $this->service_records( [ 'page'=>1 ] ) );
		}
		if ( 'pages' === $area ) return $this->response( $this->save_page( $body ) );
		if ( 'library' === $area ) {
			if ( 'attachment_field' === ( $body['operation'] ?? '' ) ) return $this->response( $this->save_attachment_field( $body ) );
			if ( ! in_array( $body['kind'] ?? '', [ 'media','testimonial' ], true ) || ! is_int( $body['id'] ?? null ) || ! is_bool( $body['selected'] ?? null ) || ! is_bool( $body['expected'] ?? null ) ) return $this->response( $this->error( 'Choose a valid library selection.' ) );
			$result = ( new Selection() )->select( $body['kind'], $body['id'], $body['selected'], $body['expected'] );
			return $this->response( is_wp_error( $result ) ? $result : [ 'id'=>$body['id'], 'selected'=>Selection::selected( $body['kind'], $body['id'] ) ] );
		}
		if ( 'feedback' === $area ) {
			$service = new Comment_Moderation_Service(); $operation = $body['operation'] ?? '';
			if ( 'status' === $operation ) return $this->response( $service->change_status( array_diff_key( $body, [ 'operation'=>true ] ) ) );
			if ( 'selection' === $operation ) return $this->response( $service->select( array_diff_key( $body, [ 'operation'=>true ] ) ) );
			return $this->response( $this->error( 'Choose a feedback action.' ) );
		}
		$current = $this->business( $area ); if ( is_wp_error( $current ) ) return $this->response( $current );
		if ( ! is_string( $body['expected'] ?? null ) || ! hash_equals( $current['expected'], $body['expected'] ) ) return $this->response( $this->error( 'Business details changed elsewhere. Reload and review before saving.', 409 ) );
		$path = $body['path'] ?? ''; $field = null; foreach ( $current['fields'] as $candidate ) if ( $candidate['path'] === $path ) $field = $candidate;
		if ( ! $field || ! array_key_exists( 'value', $body ) ) return $this->response( $this->error( 'Choose an available business field.' ) );
		$value = $body['value']; $type = $field['type'];
		if ( in_array( $type, [ 'palette','services' ], true ) ? ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > ( 'palette' === $type ? 5 : 50 ) ) : ( 'boolean' === $type ? ! is_bool( $value ) : ( in_array( $type, [ 'number','person','image' ], true ) ? ( ! is_numeric( $value ) || is_bool( $value ) || $value < 0 || ! is_finite( (float) $value ) ) : ! is_string( $value ) ) ) ) return $this->response( $this->error( 'Enter a valid value.' ) );
		if ( strlen( wp_json_encode( $value ) ) > 150000 ) return $this->response( $this->error( 'This change is too large.' ) );
		if ( is_string( $value ) && strlen( $value ) > 16000 ) return $this->response( $this->error( 'This value is too long.' ) );
		if ( isset( $field['options'] ) && ! array_key_exists( (string) $value, $field['options'] ) ) return $this->response( $this->error( 'Choose one of the available options.' ) );
		$profile = ( new Profile() )->current();
		if ( in_array( $path, [ 'contact.address.line1','contact.address.city','contact.address.country' ], true ) && ! empty( $profile['commerce']['enabled'] ) && '' === trim( (string) $value ) ) return $this->response( $this->error( 'This address detail is required for your store.' ) );
		if ( 'localization.timezone' === $path && ! in_array( $value, timezone_identifiers_list(), true ) ) return $this->response( $this->error( 'Use a timezone such as Asia/Kolkata or Europe/London.' ) );
		if ( 'contact.address.country' === $path && ! preg_match( '/^[A-Za-z]{2}$/', $value ) ) return $this->response( $this->error( 'Use a two-letter country code.' ) );
		if ( 'palette' === $type ) {
			$roles = [];
			foreach ( $value as $color ) {
				if ( ! is_array( $color ) || ! in_array( $color['role'] ?? '', [ 'brand','accent','hero','neutral','surface' ], true ) || ! is_string( $color['hex'] ?? null ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $color['hex'] ) || isset( $roles[ $color['role'] ] ) ) return $this->response( $this->error( 'Choose one valid colour per palette role.' ) );
				$roles[ $color['role'] ] = true;
			}
		}
		if ( 'image' === $type ) { $image = Workspace_Media::validate( (int) $value, (int) $current['values'][ $path ] ); if ( is_wp_error( $image ) ) return $this->response( $image ); }
		if ( str_starts_with( $path, 'about.founder.' ) ) {
			$id = 'person' === $type ? (int) $value : (int) ( $profile['about']['founder']['userId'] ?? 0 );
			if ( $id && ( ! get_userdata( $id ) || ! current_user_can( 'edit_user', $id ) ) ) return $this->response( $this->error( 'This person cannot be edited by your account.', 403 ) );
			if ( 'person' === $type && $id ) {
				$native = \DSA\Onboarding\User_Profile_Service::public_fields( $id ); $user = get_userdata( $id );
				$profile['about']['founder'] = array_replace( $profile['about']['founder'], $native, [ 'userId'=>$id, 'name'=>$user->display_name, 'bio'=>$user->description, 'title'=>(string) get_user_meta( $id, Profile::USER_META_FOUNDER_TITLE, true ), 'linkedin'=>(string) get_user_meta( $id, Profile::USER_META_LINKEDIN, true ), 'imageId'=>(int) get_user_meta( $id, Profile::USER_META_AVATAR_ID, true ) ] );
			}
		}
		if ( 'services' === $type ) {
			if ( empty( $current['service']['canEdit'] ) ) return $this->response( $this->error( 'Your account cannot edit this service collection.', 403 ) );
			$validation = $this->validate_services( $value, $current );
			if ( is_wp_error( $validation ) ) return $this->response( $validation );
			$existing = []; foreach ( $profile['services']['items'] as $item ) if ( ! empty( $item['recordId'] ) ) $existing[ (int) $item['recordId'] ] = $item;
			foreach ( $value as $item ) {
				if ( ! is_array( $item ) || ! is_int( $item['recordId'] ?? null ) || $item['recordId'] < 0 || ( $item['recordId'] && ! isset( $existing[ $item['recordId'] ] ) ) ) return $this->response( $this->error( 'Choose a service already in this collection.' ) );
				$image = Workspace_Media::validate( $item['imageId'] ?? 0, (int) ( $existing[ $item['recordId'] ]['imageId'] ?? 0 ) ); if ( is_wp_error( $image ) ) return $this->response( $image );
			}
			foreach ( array_keys( $existing ) as $id ) if ( ! in_array( $id, array_column( $value, 'recordId' ), true ) ) return $this->response( $this->error( 'Existing services must remain in this collection.' ) );
		}
		if ( 'countries' === $type ) $value = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( $value ) ) ) ) );
		if ( 'countries' === $type ) foreach ( $value as $code ) if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) return $this->response( $this->error( 'Use two-letter country codes separated by commas.' ) );
		$this->assign( $profile, $path, $value );
		$result = ( new Profile() )->save_section( $area, $profile, get_current_user_id(), '', $path );
		if ( is_wp_error( $result ) ) return $this->response( $result );
		$after = $this->business( $area );
		if ( is_wp_error( $after ) ) return $this->response( $after );
		if ( 'services' === $type ) {
			// A native writer may normalize a value or refuse an individual record.
			// Compare the requested content, not only two copies of its own result.
			foreach ( $value as $wanted ) {
				$matches = array_values( array_filter( $after['values'][ $path ], static fn( $row ) => ! empty( $wanted['recordId'] ) ? $row['recordId'] === $wanted['recordId'] : ( ( $row['stableId'] ?? '' ) === ( $wanted['stableId'] ?? '' ) || ( ! in_array( $row['recordId'], array_column( $profile['services']['items'], 'recordId' ), true ) && $row['title'] === sanitize_text_field( $wanted['title'] ) ) ) ) );
				if ( 1 !== count( $matches ) || ( $current['service']['source'] && empty( $matches[0]['recordId'] ) ) ) return $this->response( $this->error( 'A service save could not be confirmed. Reload and review the collection before retrying.', 409 ) );
				foreach ( [ 'title','summary','description','status','imageId','parentId','menuOrder' ] as $key ) {
					if ( ! array_key_exists( $key, $wanted ) ) continue;
					$expected = 'title' === $key ? sanitize_text_field( $wanted[$key] ) : ( 'summary' === $key ? sanitize_textarea_field( $wanted[$key] ) : ( 'description' === $key ? wp_kses_post( $wanted[$key] ) : $wanted[$key] ) );
					if ( $matches[0][$key] !== $expected ) return $this->response( $this->error( 'A service detail was not saved as requested. Reload and review it.', 409 ) );
				}
				if ( ! $this->service_fields_match( $wanted, $matches[0], $current['service'] ) ) return $this->response( $this->error( 'Some service fields or categories could not be saved. Reload and review before retrying.', 409 ) );
			}
		}
		if ( wp_json_encode( $this->value( $result, $path ) ) !== wp_json_encode( $after['values'][ $path ] ) ) return $this->response( $this->error( 'The saved value could not be confirmed. Reload and review it before making another change.', 409 ) );
		return $this->response( $after );
	}
	private function service_records( array $query ) {
		$catalog = ( new Profile() )->service_catalog( $query );
		$source = $catalog['sourcePostType'];
		if ( ! $source ) return $this->business( 'services' ); // Unconnected plans retain their existing owner.
		$object = get_post_type_object( $source );
		$definition = [ 'source'=>$source, 'enabled'=>$catalog['enabled'], 'hierarchical'=>! empty( $catalog['hierarchical'] ), 'taxonomies'=>$catalog['taxonomies'], 'fields'=>$catalog['customFields'] ];
		$expected = hash( 'sha256', wp_json_encode( $definition ) );
		$items = $catalog['items']; $has_next = count( $items ) > 20; $items = array_slice( $items, 0, 20 );
		$record_controls = []; $record_urls = [];
		foreach ( $items as &$item ) {
			$item['expected'] = hash( 'sha256', $expected . wp_json_encode( $item ) );
			$item['canEdit'] = current_user_can( 'edit_post', $item['recordId'] );
			$item['canHistory'] = (new Service_History_Service())->can_manage((int)$item['recordId']);
			$item['canExport'] = \DSA\Backup\Content_Archive_Service::can_export( 'service', (int) $item['recordId'] );
			$item['canTrash'] = current_user_can( 'delete_post', (int) $item['recordId'] ); $item['trashType'] = $source;
			if ( $item['recordId'] ) $record_controls[ (int) $item['recordId'] ] = Record_Card_Control_Service::project( 'service', (int) $item['recordId'] );
			if ( $item['recordId'] && 'publish' === $item['status'] && function_exists( 'get_permalink' ) ) $record_urls[ (int) $item['recordId'] ] = esc_url_raw( (string) get_permalink( $item['recordId'] ) );
		}
		unset( $item );
		$definition['canEdit'] = current_user_can( $object->cap->edit_posts );
		$definition['canCreate'] = current_user_can( $object->cap->create_posts );
		$definition['canPublish'] = current_user_can( $object->cap->publish_posts );
		$definition['categoriesUrl'] = $this->service_categories_url( $source, $object, $definition['taxonomies'] );
		if ( class_exists(Native_Service_Conversion_Service::class) ) $definition['conversionRecovery']=(new Native_Service_Conversion_Service())->undo_summary();
		$definition['images'] = []; // URLs alone do not download images on overview cards.
		foreach ( $items as $item ) if ( $item['imageId'] ) $definition['images'][$item['imageId']] = wp_get_attachment_image_url( $item['imageId'], 'medium' );
		return [ 'area'=>'services', 'recordMode'=>true, 'expected'=>$expected, 'hasNext'=>$has_next, 'service'=>$definition, 'menus'=>$this->dsa_menus(), 'recordControls'=>$record_controls, 'recordUrls'=>$record_urls, 'canUpload'=>current_user_can( 'upload_files' ), 'fields'=>self::fields()['services']['fields'], 'values'=>[ 'services.enabled'=>$catalog['enabled'], 'services.useForNavigation'=>$catalog['useForNavigation'], 'services.items'=>$items ] ];
	}

	private function save_service_record( array $body ) {
		$item = $body['value'] ?? null;
		if ( ! is_array( $item ) || ! is_int( $item['recordId'] ?? null ) || $item['recordId'] < 0 || ! is_string( $body['expected'] ?? null ) || strlen( wp_json_encode( $item ) ) > 150000 ) return $this->error( 'Choose one valid service.' );
		$current = $this->service_records( [ 'id'=>$item['recordId'], 'page'=>1 ] );
		if ( empty( $current['recordMode'] ) ) return $this->error( 'This connected service collection is unavailable.', 409 );
		if ( empty( $current['service']['canEdit'] ) ) return $this->error( 'Your role cannot edit services.', 403 );
		$old = $item['recordId'] ? ( $current['values']['services.items'][0] ?? null ) : null;
		if ( $item['recordId'] && ( ! $old || $old['recordId'] !== $item['recordId'] || ! $old['canEdit'] ) ) return $this->error( 'This service is unavailable.', 403 );
		if ( ! hash_equals( $old['expected'] ?? $current['expected'], $body['expected'] ) ) return $this->error( 'This service or its configuration changed. Reload before saving.', 409 );
		$current['singleRecord'] = true;
		$validation = $this->validate_services( [ $item ], $current );
		if ( is_wp_error( $validation ) ) return $validation;
		$image = Workspace_Media::validate( $item['imageId'] ?? 0, (int) ( $old['imageId'] ?? 0 ) ); if ( is_wp_error( $image ) ) return $image;
		$item = array_replace( [ 'summary'=>'', 'description'=>'', 'parentId'=>0, 'menuOrder'=>0, 'imageId'=>0, 'categoryPaths'=>'', 'taxonomyPaths'=>[], 'meta'=>[] ], $old ?? [], $item );
		foreach ( [ 'meta','taxonomyPaths' ] as $group ) $item[$group] = array_replace( $old[$group] ?? [], $item[$group] );
		foreach ( [ 'title','summary','description' ] as $key ) $item[$key] = 'title' === $key ? sanitize_text_field( $item[$key] ) : ( 'summary' === $key ? sanitize_textarea_field( $item[$key] ) : wp_kses_post( $item[$key] ) );
		$saved = ( new Profile() )->save_service_record( $current['service']['source'], $item, get_current_user_id() );
		if ( empty( $saved['recordId'] ) ) return $this->error( 'The service could not be created. Reload before retrying.', 409 );
		if ( empty( ( new Profile() )->current()['services']['enabled'] ) ) return $this->error( 'The service was saved, but its Design Context connection could not be confirmed. Reload before retrying.', 409 );
		$after = $this->service_records( [ 'id'=>$saved['recordId'], 'page'=>1 ] ); $actual = $after['values']['services.items'][0] ?? null;
		if ( ! $actual || $actual['recordId'] !== $saved['recordId'] ) return $this->error( 'The saved service could not be read back. Reload before retrying.', 409 );
		foreach ( [ 'title','summary','description','status','imageId','parentId','menuOrder' ] as $key ) if ( $item[$key] !== $actual[$key] ) return $this->error( 'A service detail was not saved. Reload and review it.', 409 );
		if ( ! $this->service_fields_match( $item, $actual, $current['service'] ) ) return $this->error( 'Some service fields or categories were not saved. Reload and review it.', 409 );
		$actual['url']='publish'===$actual['status'] ? esc_url_raw((string)get_permalink($actual['recordId'])) : '';
		return [ 'saved'=>$actual ];
	}

	private function service_categories_url( string $source, $object, array $taxonomies ): string {
		if ( ! $source || ! $object || ! $taxonomies || ! current_user_can( $object->cap->edit_posts ) ) return '';
		foreach ( array_keys( $taxonomies ) as $name ) {
			$taxonomy = get_taxonomy( $name );
			if ( $taxonomy && current_user_can( $taxonomy->cap->manage_terms ?? 'manage_categories' ) ) return add_query_arg( 'taxonomy', $name, Category_Workspace_Service::url() );
		}
		return '';
	}

	private function service_plan_payload( array $item ): array {
		return array_intersect_key( $item, array_fill_keys( [ 'stableId','recordId','title','summary','description','status','imageId','parentId','menuOrder','categoryPaths','taxonomyPaths','meta' ], true ) );
	}

	/** Save or remove one unconnected plan without replacing its neighbours. */
	private function save_service_plan( array $body ) {
		if ( array_diff( array_keys( $body ), [ 'operation','action','value','expected' ] ) ) return $this->error( 'Choose one service-plan change.' );
		$action = $body['action'] ?? 'save'; $item = $body['value'] ?? null;
		if ( ! in_array( $action, [ 'save','delete' ], true ) || ! is_array( $item ) || ! is_string( $body['expected'] ?? null ) || strlen( wp_json_encode( $item ) ) > 150000 ) return $this->error( 'Choose one valid service plan.' );
		if ( array_diff( array_keys( $item ), [ 'stableId','recordId','title','summary','description','status','imageId','parentId','menuOrder','categoryPaths','taxonomyPaths','meta' ] ) ) return $this->error( 'Choose only available service-plan fields.' );
		$stable_id = $item['stableId'] ?? '';
		if ( ! is_string( $stable_id ) || '' === $stable_id || strlen( $stable_id ) > 80 || sanitize_key( $stable_id ) !== $stable_id || 0 !== ( $item['recordId'] ?? null ) ) return $this->error( 'Choose one valid service plan.' );
		$current = $this->business( 'services' );
		if ( is_wp_error( $current ) ) return $current;
		if ( empty( $current['planMode'] ) || ! empty( $current['service']['source'] ) ) return $this->error( 'This website uses connected service records.', 409 );
		if ( empty( $current['service']['canEdit'] ) ) return $this->error( 'Your role cannot edit service plans.', 403 );
		$index = null; $old = null;
		foreach ( $current['values']['services.items'] as $position=>$candidate ) if ( $candidate['stableId'] === $stable_id ) { $index = $position; $old = $candidate; break; }
		if ( 'delete' === $action && ! $old ) return $this->error( 'This service plan no longer exists.', 409 );
		if ( 'save' === $action && $old && ! hash_equals( $old['expected'], $body['expected'] ) ) return $this->error( 'This service plan changed. Reload before saving.', 409 );
		if ( 'save' === $action && ! $old && ! hash_equals( $current['planExpected'], $body['expected'] ) ) return $this->error( 'The service plan list changed. Reload before adding another plan.', 409 );
		if ( 'delete' === $action && ! hash_equals( $old['expected'], $body['expected'] ) ) return $this->error( 'This service plan changed. Reload before removing it.', 409 );
		if ( 'save' === $action && ! $old && count( $current['values']['services.items'] ) >= 50 ) return $this->error( 'Service planning is limited to 50 items. Connect a native service collection to grow further.', 409 );

		$items = array_map( fn( $row ) => $this->service_plan_payload( $row ), $current['values']['services.items'] );
		if ( 'delete' === $action ) array_splice( $items, (int) $index, 1 );
		else {
			$item = array_replace( [ 'recordId'=>0, 'summary'=>'', 'description'=>'', 'status'=>'draft', 'imageId'=>0, 'parentId'=>0, 'menuOrder'=>count( $items ), 'categoryPaths'=>'', 'taxonomyPaths'=>[], 'meta'=>[] ], $old ? $this->service_plan_payload( $old ) : [], $item );
			$validation = $this->validate_services( [ $item ], $current );
			if ( is_wp_error( $validation ) ) return $validation;
			$image = Workspace_Media::validate( $item['imageId'], (int) ( $old['imageId'] ?? 0 ) ); if ( is_wp_error( $image ) ) return $image;
			if ( null === $index ) $items[] = $item; else $items[$index] = $item;
		}
		$profile = ( new Profile() )->current(); $profile['services']['items'] = array_values( $items );
		// Creating a service is the client's opt-in. An empty collection stays inactive.
		if ( 'save' === $action ) $profile['services']['enabled'] = true;
		$result = ( new Profile() )->save_section( 'services', $profile, get_current_user_id(), '', 'services.items' );
		if ( is_wp_error( $result ) ) return $result;
		$after = $this->business( 'services' ); if ( is_wp_error( $after ) ) return $after;
		$actual = null; foreach ( $after['values']['services.items'] as $candidate ) if ( $candidate['stableId'] === $stable_id ) { $actual = $candidate; break; }
		if ( 'delete' === $action ) {
			if ( $actual ) return $this->error( 'The service plan could not be removed. Reload and review it.', 409 );
			return [ 'deleted'=>$stable_id, 'planExpected'=>$after['planExpected'] ];
		}
		$canonical = null; foreach ( $result['services']['items'] ?? [] as $candidate ) if ( $candidate['stableId'] === $stable_id ) { $canonical = $candidate; break; }
		if ( ! $actual || ! $canonical || wp_json_encode( $this->service_plan_payload( $actual ) ) !== wp_json_encode( $this->service_plan_payload( $canonical ) ) ) return $this->error( 'The saved service plan could not be confirmed. Reload before retrying.', 409 );
		return [ 'saved'=>$actual, 'planExpected'=>$after['planExpected'] ];
	}

	private function service_paths( string $raw, bool $hierarchical ): array {
		$paths = [];
		foreach ( preg_split( '/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: [] as $path ) {
			$parts = $hierarchical ? preg_split( '/\s*>\s*/', trim( $path ), -1, PREG_SPLIT_NO_EMPTY ) : [ trim( $path ) ];
			$parts = array_values( array_filter( array_map( 'sanitize_text_field', $parts ), static fn( $part ) => '' !== $part ) );
			if ( $parts ) $paths[] = implode( ' > ', $parts );
		}
		$paths = array_values( array_unique( $paths ) ); sort( $paths, SORT_STRING ); return $paths;
	}
	private function service_fields_match( array $wanted, array $actual, array $definition ): bool {
		foreach ( $wanted['meta'] ?? [] as $key=>$value ) {
			$type = $definition['fields'][$key]['type']; $saved = $actual['meta'][$key] ?? '';
			if ( 'boolean' === $type ) { if ( (bool) $value !== (bool) $saved ) return false; }
			elseif ( in_array( $type, [ 'integer','number' ], true ) ) {
				if ( '' === (string) $value ? '' !== (string) $saved : ( '' === (string) $saved || ! is_numeric( $saved ) || (float) $value !== (float) $saved ) ) return false;
			} elseif ( sanitize_textarea_field( (string) $value ) !== (string) $saved ) return false;
		}
		foreach ( $definition['taxonomies'] as $name=>$taxonomy ) {
			if ( ! array_key_exists( $name, $wanted['taxonomyPaths'] ?? [] ) ) continue;
			if ( $this->service_paths( $wanted['taxonomyPaths'][$name], $taxonomy['hierarchical'] ) !== $this->service_paths( $actual['taxonomyPaths'][$name] ?? '', $taxonomy['hierarchical'] ) ) return false;
		}
		return $definition['taxonomies'] || ! array_key_exists( 'categoryPaths', $wanted ) || sanitize_textarea_field( $wanted['categoryPaths'] ) === ( $actual['categoryPaths'] ?? '' );
	}
	private function validate_services( array $items, array $current ) {
		$meta = $current['service']; $old = [];
		foreach ( $current['values']['services.items'] as $row ) $old[ $row['recordId'] ?: $row['stableId'] ] = $row;
		$seen = [];
		$parents = []; foreach ( $items as $row ) if ( is_array( $row ) && is_int( $row['recordId'] ?? null ) && $row['recordId'] > 0 ) $parents[$row['recordId']] = is_int( $row['parentId'] ?? null ) ? $row['parentId'] : 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_int( $item['recordId'] ?? null ) || ! is_string( $item['stableId'] ?? null ) || strlen( $item['stableId'] ) > 100 || ! is_string( $item['title'] ?? null ) || '' === trim( $item['title'] ) || strlen( $item['title'] ) > 800 ) return $this->error( 'Every service needs a name and a valid record.' );
			$key = $item['recordId'] ?: $item['stableId']; if ( isset( $seen[$key] ) ) return $this->error( 'A service cannot appear twice.' ); $seen[$key] = true;
			$before = $old[$key] ?? [];
			foreach ( [ 'summary','description','categoryPaths' ] as $field ) if ( isset( $item[$field] ) && ( ! is_string( $item[$field] ) || strlen( $item[$field] ) > 16000 ) ) return $this->error( 'Service text is invalid or too long.' );
			foreach ( [ 'imageId','parentId','menuOrder' ] as $field ) if ( isset( $item[$field] ) && ( ! is_int( $item[$field] ) || $item[$field] < 0 ) ) return $this->error( 'Choose valid service images, parents and order.' );
			$parent = $item['parentId'] ?? 0; $chain = [];
			while ( $parent ) {
				if ( ! empty( $current['singleRecord'] ) && ! isset( $parents[$parent] ) ) {
					$ancestor = get_post( $parent );
					if ( ! $ancestor || $ancestor->post_type !== $meta['source'] || ! in_array( $ancestor->post_status, [ 'publish','draft','pending' ], true ) || ! current_user_can( 'edit_post', $parent ) || count( $chain ) >= 100 ) return $this->error( 'Choose an accessible parent service without creating a loop.' );
					$parents[$parent] = (int) $ancestor->post_parent;
				}
				if ( empty( $meta['hierarchical'] ) || ! isset( $parents[$parent] ) || $parent === $item['recordId'] || isset( $chain[$parent] ) ) return $this->error( 'Choose a parent in this collection without creating a loop.' );
				$chain[$parent] = true; $parent = $parents[$parent];
			}
			if ( ! in_array( $item['status'] ?? '', [ 'draft','pending','publish' ], true ) ) return $this->error( 'Choose a valid service status.' );
			if ( ! $item['recordId'] && ! $meta['canCreate'] ) return $this->error( 'Your role cannot create services.', 403 );
			if ( $meta['source'] && 'publish' === $item['status'] && 'publish' !== ( $before['status'] ?? '' ) && ! $meta['canPublish'] ) return $this->error( 'Your role cannot publish services.', 403 );
			if ( ( $item['categoryPaths'] ?? '' ) !== ( $before['categoryPaths'] ?? '' ) ) foreach ( $meta['taxonomies'] as $name=>$definition ) { $tax = get_taxonomy( $name ); if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) || ! current_user_can( $tax->cap->manage_terms ) ) return $this->error( 'Your role cannot change these category paths.', 403 ); }
			foreach ( [ 'meta','taxonomyPaths' ] as $group ) {
				if ( isset( $item[$group] ) && ! is_array( $item[$group] ) ) return $this->error( 'Choose valid service fields and categories.' );
				foreach ( $item[$group] ?? [] as $name=>$value ) {
					if ( ! is_scalar( $value ) || ( 'taxonomyPaths' === $group && ! is_string( $value ) ) || strlen( (string) $value ) > 16000 ) return $this->error( 'A service field is invalid.' );
					$definitions = 'meta' === $group ? $meta['fields'] : $meta['taxonomies'];
					if ( ! isset( $definitions[$name] ) ) return $this->error( 'Choose a configured service field.' );
					if ( 'meta' === $group ) {
						$type = $definitions[$name]['type'];
						if ( 'boolean' === $type && ! is_bool( $value ) ) return $this->error( 'Choose on or off for this service field.' );
						if ( in_array( $type, [ 'integer','number' ], true ) && '' !== $value && ( is_bool( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || ( 'integer' === $type && (float) $value !== floor( (float) $value ) ) ) ) return $this->error( 'Enter a valid number for this service field.' );
					}
					if ( $value === ( $before[$group][$name] ?? '' ) ) continue;
					if ( 'meta' === $group && $item['recordId'] && ! current_user_can( 'edit_post_meta', $item['recordId'], $name ) ) return $this->error( 'Your role cannot edit this service field.', 403 );
					if ( 'taxonomyPaths' === $group ) { $tax = get_taxonomy( $name ); if ( ! $tax || ! current_user_can( $tax->cap->assign_terms ) || ! current_user_can( $tax->cap->manage_terms ) ) return $this->error( 'Your role cannot change these category paths.', 403 ); }
				}
			}
		}
		return null;
	}
	private function page( $post ): array {
		$state = [ 'id'=>(int) $post->ID, 'title'=>$post->post_title, 'status'=>$post->post_status, 'noindex'=>SEO_Context_Service::page_noindex( (int) $post->ID ) ];
		$publishing = new Page_Publishing_Service();
		$state['expected'] = Page_Publishing_Service::fingerprint( $post );
		$state['url'] = 'publish' === $post->post_status ? get_permalink( $post ) : '';
		$state['scheduledLocal'] = 'future' === $post->post_status ? substr( str_replace( ' ', 'T', (string) $post->post_date ), 0, 16 ) : '';
		$state['modifiedGmt'] = (string) $post->post_modified_gmt;
		$state['canEditPublishing'] = $publishing->can_manage_publishing( (int) $post->ID );
		$state['canRestore'] = $publishing->can_restore( (int) $post->ID );
		$state['canPublish'] = $publishing->can_publish();
		$state['canEditSeo'] = in_array( $post->post_status, [ 'publish','draft','pending','future' ], true ) && '' === (string) $post->post_password && ( current_user_can( 'edit_post', (int) $post->ID ) || current_user_can( 'kiwe_manage_page_indexing' ) );
		$state['builderUrl'] = Template_Workspace_Service::builder_url( (int) $post->ID );
		$state['canTrash'] = current_user_can( 'delete_post', (int) $post->ID ) && ! Page_Publishing_Service::critical_reason( (int) $post->ID );
		$state['canExport'] = class_exists( \DSA\Backup\Content_Archive_Service::class ) && \DSA\Backup\Content_Archive_Service::can_export( 'page', (int) $post->ID );
		$state['criticalReason'] = Page_Publishing_Service::critical_reason( (int) $post->ID );
		$state['menuUrl'] = current_user_can( 'kiwe_manage_navigation' ) ? Navigation_Workspace_Service::url( 'page', (int) $post->ID ) : '';
		return $state;
	}
	private function dsa_menus(): array {
		return ( new Navigation_Workspace_Service() )->dsa_menus();
	}
	private function pages( \WP_REST_Request $request ): array {
		$page = max( 1, min( 10000, (int) $request->get_param( 'page' ) ) );
		$allowed_statuses = current_user_can( 'read_private_pages' ) ? [ 'publish','draft','pending','private','future' ] : [ 'publish','draft','pending','future' ];
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$statuses = in_array( $status, $allowed_statuses, true ) ? [ $status ] : $allowed_statuses;
		$sort = sanitize_key( (string) $request->get_param( 'sort' ) );
		if ( ! in_array( $sort, [ 'modified_desc','modified_asc','date_desc','date_asc','title_asc','title_desc' ], true ) ) $sort = 'modified_desc';
		[ $orderby, $order ] = explode( '_', $sort, 2 );
		$records = get_posts( [ 'post_type'=>'page', 'post_status'=>$statuses, 'posts_per_page'=>21, 'offset'=>( $page - 1 ) * 20, 's'=>Task_Workspace_UI::text( $request->get_param( 'search' ) ), 'orderby'=>[ $orderby=>strtoupper( $order ), 'ID'=>strtoupper( $order ) ], 'no_found_rows'=>true, 'update_post_term_cache'=>false ] );
		return [ 'items'=>array_map( [ $this, 'page' ], array_slice( $records, 0, 20 ) ), 'menus'=>$this->dsa_menus(), 'page'=>$page, 'hasNext'=>count( $records ) > 20, 'canCreate'=>current_user_can( 'manage_options' ) || current_user_can( 'kiwe_create_page_drafts' ), 'canRename'=>current_user_can( 'manage_options' ) || current_user_can( 'kiwe_manage_page_titles' ), 'canIndex'=>SEO_Context_Service::indexing_owner_supported() && ( current_user_can( 'manage_options' ) || current_user_can( 'kiwe_manage_page_indexing' ) ), 'canReadPrivate'=>in_array( 'private', $allowed_statuses, true ) ];
	}
	private function save_page( array $body ) {
		$service = new Page_Workspace_Service();
		if ( 'create' === ( $body['operation'] ?? '' ) ) {
			$id = $service->create_draft( [ 'page_title'=>$body['title'] ?? '', 'indexing'=>'noindex' ] );
			return is_wp_error( $id ) ? $id : $this->page( get_post( $id ) );
		}
		$id = $body['id'] ?? 0; if ( ! is_int( $id ) || $id < 1 ) return $this->error( 'Choose a page.' );
		$post = get_post( $id ); if ( ! $post || 'page' !== $post->post_type || ! in_array( $post->post_status, [ 'publish','draft','pending','private','future' ], true ) || ( 'private' === $post->post_status && ! current_user_can( 'read_private_pages' ) ) ) return $this->error( 'Page unavailable.', 404 );
		$current = $this->page( $post );
		if ( ! is_string( $body['expected'] ?? null ) || ! hash_equals( $current['expected'], $body['expected'] ) ) return $this->error( 'This page changed. Reload before saving.', 409 );
		if ( 'publishing' === ( $body['operation'] ?? '' ) ) {
			$input = array_intersect_key( $body, [ 'expected'=>true, 'status'=>true, 'scheduledLocal'=>true ] );
			$result = ( new Page_Publishing_Service() )->save( $id, $input );
			return is_wp_error( $result ) ? $result : $this->page( get_post( $id ) );
		}
		if ( 'restore' === ( $body['operation'] ?? '' ) ) {
			$revision_id = $body['revisionId'] ?? 0;
			if ( ! is_int( $revision_id ) || $revision_id < 1 ) return $this->error( 'Choose a saved page version.' );
			$input = array_intersect_key( $body, [ 'expected'=>true, 'revisionExpected'=>true ] );
			$result = ( new Page_Publishing_Service() )->restore( $id, $revision_id, $input );
			return is_wp_error( $result ) ? $result : $this->page( get_post( $id ) );
		}
		if ( 'menu' === ( $body['operation'] ?? '' ) ) {
			$menu = null; foreach ( $this->dsa_menus() as $candidate ) if ( $candidate['id'] === ( $body['menuId'] ?? null ) ) $menu = $candidate;
			if ( ! $menu || ! is_bool( $body['included'] ?? null ) ) return $this->error( 'Choose a configured DSA menu.' );
			$item = null; foreach ( $menu['items'] as $entry ) if ( 'page' === $entry['object'] && $entry['objectId'] === $id ) $item = $entry;
			if ( ! is_string( $body['menuExpected'] ?? null ) || ! hash_equals( $menu['expected'], $body['menuExpected'] ) ) return $this->error( 'The menu changed. Reload before saving.', 409 );
			if ( $body['included'] === (bool) $item ) return $current;
			$result = ( new Navigation_Workspace_Service() )->change( $menu['id'], [ 'operation'=>$body['included'] ? 'add' : 'remove', 'source_kind'=>'page', 'source_id'=>$id, 'parent_id'=>0, 'item_id'=>$item['id'] ?? 0, 'expected'=>$menu['expected'] ] );
			return is_wp_error( $result ) ? $result : $this->page( get_post( $id ) );
		}
		if ( 'rename' === ( $body['operation'] ?? '' ) ) $result = $service->rename_page( $id, [ 'page_title'=>$body['title'] ?? null, 'expected_title'=>$post->post_title ] );
		elseif ( 'index' === ( $body['operation'] ?? '' ) && is_bool( $body['noindex'] ?? null ) ) $result = $service->save_indexing( $id, $body['noindex'] ? 'noindex' : 'index' );
		else return $this->error( 'Choose a page action.' );
		return is_wp_error( $result ) ? $result : $this->page( get_post( $id ) );
	}
	private function library( \WP_REST_Request $request ) {
		$kind = 'media';
		$data = ( new Context_Workspace_Service() )->listing( $kind, [ 'result_page'=>$request->get_param( 'page' ), 'search'=>$request->get_param( 'search' ), 'view'=>$request->get_param( 'view' ), 'sort'=>$request->get_param( 'sort' ) ] );
		if ( is_wp_error( $data ) ) return $data;
		$items = [];
		foreach ( $data['items'] as $record ) {
			$id = (int) ( 'media' === $kind ? $record->ID : $record->comment_ID );
			$public = 'media' === $kind ? Selection::public_media( $record ) : Selection::public_testimonial( $record );
			$state = \DSA\Onboarding\Context_Media_Service::state( $id );
			$items[] = [ 'id'=>$id, 'title'=>$record->post_title, 'summary'=>$record->post_mime_type, 'visibility'=>$public ? 'public' : 'private', 'image'=>wp_attachment_is_image( $id ) ? wp_get_attachment_image_url( $id, 'medium' ) : '', ...$state, 'canEdit'=>current_user_can( 'edit_post', $id ),'canTrash'=>current_user_can( 'delete_post', $id ),'trashExpected'=>Trash_Workspace_Service::fingerprint( $record, 'attachment' ), 'canArchive'=>\DSA\Backup\Media_Archive_Service::can_export( $id ) ];
		}
		return [ 'items'=>$items, 'page'=>$data['page'], 'sort'=>$data['sort'], 'hasNext'=>$data['has_more'], 'canTestimonials'=>current_user_can( 'moderate_comments' ), 'canUpload'=>current_user_can( 'upload_files' ),'indexReady'=>\DSA\Onboarding\Context_Media_Service::ensure_index() ];
	}
	private function attachment( int $id ) {
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) return $this->error( 'This file is unavailable.', 404 );
		$is_image = wp_attachment_is_image( $id );
		$data = [
			'id'=>$id,
			'title'=>(string) $post->post_title,
			'caption'=>(string) $post->post_excerpt,
			'description'=>(string) $post->post_content,
			'alt'=>$is_image ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '',
			'mime'=>(string) $post->post_mime_type,
			'isImage'=>$is_image,
			...\DSA\Onboarding\Context_Media_Service::state( $id ),
		];
		$data['expected'] = hash( 'sha256', wp_json_encode( $data ) );
		return $data;
	}
	private function save_attachment_field( array $body ) {
		if ( array_diff( array_keys( $body ), [ 'operation','id','field','value','expected' ] ) || ! is_int( $body['id'] ?? null ) || ! is_string( $body['field'] ?? null ) || ! is_string( $body['value'] ?? null ) || ! is_string( $body['expected'] ?? null ) ) return $this->error( 'Choose one available file detail.' );
		$id = $body['id']; $current = $this->attachment( $id );
		if ( is_wp_error( $current ) ) return $current;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $body['expected'] ) || ! hash_equals( $current['expected'], $body['expected'] ) ) return $this->error( 'This file changed elsewhere. Reload its details before saving.', 409 );
		$field = $body['field'];
		$definitions = [ 'title'=>[ 'post_title', 500 ], 'caption'=>[ 'post_excerpt', 4000 ], 'description'=>[ 'post_content', 16000 ], 'alt'=>[ '_wp_attachment_image_alt', 1000 ] ];
		if ( ! isset( $definitions[ $field ] ) || ( 'alt' === $field && ! $current['isImage'] ) ) return $this->error( 'Choose an available file detail.' );
		$value = in_array( $field, [ 'caption','description' ], true ) ? wp_kses_post( $body['value'] ) : sanitize_text_field( $body['value'] );
		if ( strlen( $value ) > $definitions[ $field ][1] ) return $this->error( 'This file detail is too long.' );
		if ( $value === $current[ $field ] ) return $current;
		if ( 'alt' === $field ) {
			$result = '' === $value ? delete_post_meta( $id, $definitions[ $field ][0] ) : update_post_meta( $id, $definitions[ $field ][0], wp_slash( $value ) );
			if ( false === $result && (string) get_post_meta( $id, $definitions[ $field ][0], true ) !== $value ) return $this->error( 'WordPress could not save this image description.', 409 );
		} else {
			$result = wp_update_post( wp_slash( [ 'ID'=>$id, $definitions[ $field ][0]=>$value ] ), true );
			if ( is_wp_error( $result ) ) return $this->error( 'WordPress could not save this file detail.', 409 );
		}
		$saved = $this->attachment( $id );
		if ( is_wp_error( $saved ) || $saved[ $field ] !== $value ) return $this->error( 'The saved file detail could not be confirmed. Reload before trying again.', 409 );
		return $saved;
	}
	public static function feedback_url(): string { return admin_url( 'admin.php?page=kiwe-feedback' ); }
	public static function timeline_url(): string { return admin_url( 'admin.php?page=kiwe-timeline' ); }
	private static function timeline_version_fingerprint( \WP_Post $revision ): string {
		return Task_Workspace_UI::revision_fingerprint( $revision );
	}
	private function timeline_can_review_record( \WP_Post $parent ): bool {
		// Pages has its own narrow recovery authority; generic layout editing stays protected.
		return ( 'page' === $parent->post_type && ( new Page_Publishing_Service() )->can_restore( (int) $parent->ID ) ) || current_user_can( 'edit_post', $parent->ID );
	}
	private function timeline_can_review_autosave( object $revision, object $parent ): bool {
		if ( ! wp_is_post_autosave( $revision ) ) return true;
		if ( (int) $revision->post_author === get_current_user_id() ) return true;
		$type = get_post_type_object( $parent->post_type );
		return $type && ! empty( $type->cap->edit_others_posts ) && current_user_can( $type->cap->edit_others_posts );
	}
	private function timeline_version( int $id ) {
		$revision = $id ? wp_get_post_revision( $id ) : null;
		$parent = $revision ? get_post( (int) $revision->post_parent ) : null;
		$types = $this->timeline_types();
		if ( ! $revision || ! $parent || ! isset( $types[ $parent->post_type ] ) || ! $this->timeline_can_review_record( $parent ) || ! $this->timeline_can_review_autosave( $revision, $parent ) ) return $this->error( 'This version is unavailable for your account.', 403 );
		if ((new Service_History_Service())->can_manage((int)$parent->ID)) return (new Service_History_Service())->version($id);
		$recovery = (bool) wp_is_post_autosave( $revision );
		$owner_copy = ! $recovery || (int) $revision->post_author === get_current_user_id();
		$versions = 'post' === $parent->post_type ? ( new Post_Workspace_Service() )->revisions( $parent->ID ) : ( 'page' === $parent->post_type ? ( new Page_Publishing_Service() )->revisions( $parent->ID ) : null );
		$can_restore = $owner_copy && is_array( $versions );
		$expected = 'post' === $parent->post_type ? Post_Workspace_Service::fingerprint( $parent ) : ( 'page' === $parent->post_type ? Page_Publishing_Service::fingerprint( $parent ) : '' );
		return [
			'id'=>(int) $revision->ID, 'recordId'=>(int) $parent->ID, 'type'=>$parent->post_type,
			'typeLabel'=>$types[ $parent->post_type ], 'recovery'=>$recovery,
			'title'=>(string) $revision->post_title, 'contentHtml'=>wp_kses_post( (string) $revision->post_content ), 'excerptHtml'=>wp_kses_post( (string) $revision->post_excerpt ),
			'currentTitle'=>(string) $parent->post_title, 'currentHtml'=>wp_kses_post( (string) $parent->post_content ), 'currentExcerptHtml'=>wp_kses_post( (string) $parent->post_excerpt ),
			'when'=>mysql_to_rfc3339( (string) $revision->post_modified_gmt ),
			'expected'=>self::timeline_version_fingerprint( $revision ), 'recordExpected'=>$expected,
			'canRestore'=>$can_restore, 'canDiscard'=>$can_restore && $recovery,
		];
	}
	private function timeline_action( array $input ) {
		$operation = sanitize_key( (string) ( $input['operation'] ?? '' ) );
		if ( ! in_array( $operation, [ 'restore','discard' ], true ) || array_diff( array_keys( $input ), [ 'operation','id','expected','recordExpected' ] ) || ! is_int( $input['id'] ?? null ) || ! is_string( $input['expected'] ?? null ) || ! is_string( $input['recordExpected'] ?? null ) ) return $this->error( 'Review the current version before choosing an action.' );
		$version = $this->timeline_version( $input['id'] );
		if ( is_wp_error( $version ) ) return $version;
		if ( ! hash_equals( $version['expected'], $input['expected'] ) || ! hash_equals( $version['recordExpected'], $input['recordExpected'] ) ) return $this->error( 'This record changed. Reload its version before continuing.', 409 );
		if ( 'discard' === $operation && ! $version['canDiscard'] ) return $this->error( 'Only your own unsaved recovery copy can be discarded here.', 403 );
		if ( 'restore' === $operation && ! $version['canRestore'] ) return $this->error( 'This version cannot be restored here.', 403 );
		$id = $version['recordId'];
		if ( 'post' === $version['type'] ) $result = 'restore' === $operation ? ( new Post_Workspace_Service() )->restore_revision( $id, $version['id'], [ 'expected'=>$input['recordExpected'], 'revisionExpected'=>$input['expected'] ] ) : ( new Post_Workspace_Service() )->discard_autosave( $id, $version['id'], [ 'expected'=>$input['recordExpected'], 'recoveryExpected'=>$input['expected'] ] );
		elseif ( 'page' === $version['type'] ) $result = 'restore' === $operation ? ( new Page_Publishing_Service() )->restore( $id, $version['id'], [ 'expected'=>$input['recordExpected'], 'revisionExpected'=>$input['expected'] ] ) : ( new Page_Publishing_Service() )->discard_autosave( $id, $version['id'], [ 'expected'=>$input['recordExpected'], 'recoveryExpected'=>$input['expected'] ] );
		elseif ((new Service_History_Service())->can_manage($id)) $result = 'restore' === $operation ? (new Service_History_Service())->restore($id,$version['id'],['expected'=>$input['recordExpected'],'revisionExpected'=>$input['expected']]) : (new Service_History_Service())->discard_autosave($id,$version['id'],['expected'=>$input['recordExpected'],'recoveryExpected'=>$input['expected']]);
		else return $this->error( 'This record type has no reviewed restore action.', 403 );
		return is_wp_error( $result ) ? $result : [ 'ok'=>true, 'operation'=>$operation, 'recordId'=>$id ];
	}
	private function timeline_types(): array {
		$profile = ( new Profile() )->current();
		$service = sanitize_key( (string) ( $profile['services']['sourcePostType'] ?? '' ) );
		$names = array_unique( array_filter( [ 'post','page',\DSA\Apps\Store_App_Service::enabled() ? 'product' : '',$service ] ) );
		$out = [];
		foreach ( $names as $name ) {
			$type = get_post_type_object( $name );
			if ( $type && ( ( ! empty( $type->cap->edit_posts ) && current_user_can( $type->cap->edit_posts ) ) || ( 'page' === $name && current_user_can( Page_Publishing_Service::CAP_RESTORE ) ) ) ) $out[ $name ] = (string) ( $type->labels->singular_name ?? $type->label );
		}
		return $out;
	}
	private function timeline_allowed(): bool { return current_user_can( 'read' ) && (bool) $this->timeline_types(); }
	public function can_view_timeline(): bool { return self::enabled() && $this->timeline_allowed(); }
	private function timeline( \WP_REST_Request $request ) {
		$types = $this->timeline_types();
		if ( ! $types ) return $this->error( 'Timeline is unavailable for this account.', 403 );
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( $type && 'all' !== $type && ! isset( $types[ $type ] ) ) return $this->error( 'Choose a record type available to your role.', 403 );
		$page = max( 1, min( 500, absint( $request->get_param( 'page' ) ) ) );
		$sort = sanitize_key( (string) $request->get_param( 'sort' ) );
		if ( ! in_array( $sort, [ 'date_desc','date_asc' ], true ) ) $sort = 'date_desc';
		$author = absint( $request->get_param( 'author' ) );
		$search = Task_Workspace_UI::text( (string) $request->get_param( 'search' ), 100 );
		$from = (string) $request->get_param( 'from' ); $to = (string) $request->get_param( 'to' );
		foreach ( [ $from,$to ] as $date ) if ( '' !== $date && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || gmdate( 'Y-m-d', strtotime( $date ) ) !== $date ) ) return $this->error( 'Choose a valid date.' );
		global $wpdb;
		$included = $type && 'all' !== $type ? [ $type ] : array_keys( $types );
		$placeholders = implode( ',', array_fill( 0, count( $included ), '%s' ) );
		$where = [ "r.post_type = 'revision'", "r.post_status = 'inherit'", "p.post_type IN ($placeholders)", "p.post_status NOT IN ('trash','auto-draft')" ];
		$bindings = $included;
		if ( $author ) { $where[] = 'r.post_author = %d'; $bindings[] = $author; }
		if ( $search ) { $where[] = 'p.post_title LIKE %s'; $bindings[] = '%' . $wpdb->esc_like( $search ) . '%'; }
		if ( $from ) { $where[] = 'r.post_modified_gmt >= %s'; $bindings[] = $from . ' 00:00:00'; }
		if ( $to ) { $where[] = 'r.post_modified_gmt <= %s'; $bindings[] = $to . ' 23:59:59'; }
		$order = 'date_asc' === $sort ? 'ASC' : 'DESC';
		$limit = 41; $offset = ( $page - 1 ) * 40;
		$bindings[] = $limit; $bindings[] = $offset;
		$sql = "SELECT r.ID FROM {$wpdb->posts} r INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent WHERE " . implode( ' AND ', $where ) . " ORDER BY r.post_modified_gmt $order, r.ID $order LIMIT %d OFFSET %d";
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$bindings ) );
		$more = count( $ids ) > 40;
		$items = [];
		foreach ( array_slice( $ids, 0, 40 ) as $id ) {
			$revision = get_post( (int) $id );
			if ( ! $revision ) continue;
			$parent = get_post( (int) $revision->post_parent );
			if ( ! $parent || ! isset( $types[ $parent->post_type ] ) || ! $this->timeline_can_review_record( $parent ) ) continue;
			if ( ! $this->timeline_can_review_autosave( $revision, $parent ) ) continue;
			$recovery = (bool) wp_is_post_autosave( $revision );
			if ( $recovery && Task_Workspace_UI::revision_text_matches( $revision, $parent ) ) continue;
			$user = get_userdata( (int) $revision->post_author );
			$items[] = [ 'id'=>(int) $revision->ID, 'recordId'=>(int) $parent->ID, 'title'=>get_the_title( $parent ), 'type'=>$parent->post_type, 'typeLabel'=>$types[ $parent->post_type ], 'recovery'=>$recovery, 'author'=>$user ? $user->display_name : __( 'Unknown author', 'dsa' ), 'authorId'=>(int) $revision->post_author, 'when'=>mysql_to_rfc3339( (string) $revision->post_modified_gmt ) ];
		}
		$authors = [];
		$can_see_others = current_user_can( 'edit_others_posts' ) || current_user_can( 'edit_others_pages' ) || current_user_can( 'edit_others_products' );
		foreach ( $can_see_others ? get_users( [ 'number'=>100, 'orderby'=>'display_name', 'fields'=>[ 'ID','display_name' ], 'who'=>'authors' ] ) : [ wp_get_current_user() ] as $user ) $authors[ (int) $user->ID ] = [ 'id'=>(int) $user->ID, 'name'=>(string) $user->display_name ];
		foreach ( $items as $item ) $authors[ $item['authorId'] ] = [ 'id'=>$item['authorId'], 'name'=>$item['author'] ];
		return [ 'area'=>'timeline', 'items'=>$items, 'page'=>$page, 'hasNext'=>$more, 'types'=>$types, 'authors'=>array_values( $authors ), 'sort'=>$sort ];
	}
	public function document(): void {
		$slug = sanitize_key( $_GET['page'] ?? '' ); $area = [ 'kiwe-pages'=>'pages','kiwe-context-library'=>'library','kiwe-store-plan'=>'store' ][ $slug ] ?? substr( $slug, 5 );
		$allowed = 'pages' === $area ? ( current_user_can( 'kiwe_view_pages' ) || current_user_can( 'manage_options' ) ) : ( 'feedback' === $area ? current_user_can( 'moderate_comments' ) : ( 'timeline' === $area ? $this->timeline_allowed() : current_user_can( 'kiwe_manage_context' ) ) );
		if ( ! self::enabled() || ! $allowed || ! is_admin() || is_network_admin() || is_user_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', [ 'GET','HEAD' ], true ) ) return;
		if ('library' === $area && 'testimonial' === ($_GET['kind'] ?? '') && current_user_can('moderate_comments')) { wp_safe_redirect(Context_Workspace_Service::url('testimonial')); exit; }
		nocache_headers(); header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true ); header( 'X-Robots-Tag: noindex, nofollow', true ); header( 'X-Content-Type-Options: nosniff', true );
		if ( 'HEAD' === $_SERVER['REQUEST_METHOD'] ) exit;
		$media = in_array( $area, [ 'library','story','services' ], true ); if ( $media ) Workspace_Media::enqueue();
		if ('services'===$area) wp_enqueue_editor();
		wp_enqueue_style( 'kiwe-continuity', DSA_URL . 'assets/css/continuity-app.css', \DSA\Access\Workspace_Design_Service::app_dependencies( [] ), DSA_VERSION );
		wp_enqueue_script( 'kiwe-continuity', DSA_URL . 'assets/js/continuity-app.js', [ 'wp-element' ], DSA_VERSION, true );
		wp_localize_script( 'kiwe-continuity', 'KIWE_CONTINUITY_APP', [ 'endpoint'=>rest_url( 'dsa/v1/workspace/continuity/' . $area ), 'controlsEndpoint'=>rest_url( 'dsa/v1/workspace/record-controls' ), 'navigationEndpoint'=>rest_url( 'dsa/v1/workspace/navigation' ), 'seoEndpoint'=>\DSA\AI\AI_Feature_Policy::seo_enabled() ? rest_url( 'dsa/v1/workspace/seo' ) : '', 'nonce'=>wp_create_nonce( 'wp_rest' ), 'home'=>Workspace_Admin_Service::url(), ...Workspace_Chrome_Service::projection(), ...( class_exists( Content_Archive_App_Service::class ) ? Content_Archive_App_Service::config() : [] ), 'area'=>$area, 'initialMediaView'=>in_array( $_GET['view'] ?? '', [ 'all','public','private','selected' ], true ) ? $_GET['view'] : 'all', 'initialKind'=>'media','initialFeedbackType'=>in_array($_GET['type'] ?? '', ['all','comment','review','testimonial'],true) ? $_GET['type'] : 'all', 'businessHome'=>admin_url( 'admin.php?page=kiwe-identity' ), 'areas'=>$this->area_urls(), 'pages'=>self::url(), 'taxes'=>admin_url('admin.php?page=kiwe-store&task=tax'), 'mediaEndpoint'=>rest_url('wp/v2/media'),'contextEndpoint'=>rest_url('dsa/v1/workspace/context/media'),'canCurate'=>current_user_can('kiwe_manage_context'),'trashUrl'=>Trash_Workspace_Service::url( 'attachment' ),'canSeoMedia'=>'library' === $area && current_user_can( 'upload_files' ), 'seoUrl'=>\DSA\Apps\SEO_App_Service::url() ] );
		?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( __( 'Website workspace', 'dsa' ) . ' · ' . get_bloginfo( 'name' ) ); ?></title><?php if ( $media ) Workspace_Media::styles(); wp_print_styles( 'services'===$area ? [ 'editor-buttons','kiwe-continuity' ] : [ 'kiwe-continuity' ] ); ?></head><body><div id="kiwe-continuity"><p>Opening your workspace…</p></div><?php if ( $media ) Workspace_Media::footer(); if ('services'===$area) { wp_print_scripts(['editor','quicktags','wp-element']); \_WP_Editors::print_default_editor_scripts(); } wp_print_scripts( [ 'wp-element','kiwe-continuity' ] ); ?></body></html><?php exit;
	}
}

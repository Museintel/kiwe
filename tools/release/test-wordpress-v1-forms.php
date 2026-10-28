<?php
/** Real native Bricks save/SQL/inbox integration, in the owned local schema only. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_V1_OWNED_DATABASE' ) || DB_NAME !== KIWE_V1_OWNED_DATABASE ) throw new RuntimeException( 'Owned native fixture required.' );

function kiwe_v1_forms_tests( callable $check ): void {
	global $wpdb;
	if ( ! defined( 'KIWE_NATIVE_BRICKS_SOURCE' ) || ! is_file( KIWE_NATIVE_BRICKS_SOURCE . '/includes/autoloader.php' ) ) throw new RuntimeException( 'Explicit actual Bricks source required.' );
	$native_theme = static fn() => KIWE_NATIVE_BRICKS_SOURCE;
	add_filter( 'template_directory', $native_theme );
	try { require_once KIWE_NATIVE_BRICKS_SOURCE . '/functions.php'; }
	finally { remove_filter( 'template_directory', $native_theme ); }
	wp_set_current_user( 1 );
	Bricks\Integrations\Form\Submission_Database::maybe_create_table();
	$table = Bricks\Integrations\Form\Submission_Database::get_table_name();
	$check( $table === $wpdb->prefix . 'bricks_form_submissions' && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table, 'Actual native Bricks creates its own submissions table' );
	$native = new Bricks\Integrations\Form\Actions\Save_Submission( 'save-submission' );
	$notices = [];
	add_action( 'kiwe_form_submission_saved', static function( $id ) use ( &$notices ) { $notices[] = $id; } );
	add_filter( 'bricks/form/save-submission/form_data', [ DSA\Bricks\Form_Identity_Service::class, 'capture' ], 10, 3 );
	$preferences = new DSA\Notifications\Notification_Preference_Service( new DSA\Settings(), new DSA\Trust\Trust_Service() );
	$preferences->maybe_install();
	$notifier = new DSA\Notifications\Admin_Event_Notification_Service( $preferences, new DSA\Notifications\Push_Service() );
	add_action( 'kiwe_form_submission_saved', [ $notifier, 'queue_form' ], 20 );
	$make = static function( string $label, string $key ) use ( $native ): array {
		$post = wp_insert_post( [ 'post_type'=>'page', 'post_status'=>'private', 'post_title'=>$label . ' fixture' ] );
		$settings = [ '_attributes'=>[ [ 'name'=>'data-kiwe-form', 'value'=>$key ], [ 'name'=>'data-kiwe-form-label', 'value'=>$label ] ], 'fields'=>[ [ 'id'=>'v1name', 'type'=>'text', 'label'=>'Your name' ], [ 'id'=>'v1mail', 'type'=>'email', 'label'=>'Email' ], [ 'id'=>'v1body', 'type'=>'textarea', 'label'=>'Message' ] ], 'actions'=>[ 'save-submission' ], 'submissionFormName'=>$label ];
		update_post_meta( $post, BRICKS_DB_PAGE_CONTENT, [ [ 'id'=>'v1form', 'name'=>'form', 'parent'=>0, 'children'=>[], 'settings'=>$settings ] ] );
		$form = new class( $post, $settings ) {
			public array $result = [];
			public function __construct( public int $post, public array $settings ) {}
			public function get_settings() { return $this->settings; }
			public function get_fields() { return [ 'postId'=>$this->post, 'formId'=>'v1form' ]; }
			public function get_field_value( $id ) { return [ 'v1name'=>'Synthetic Enquirer', 'v1mail'=>'forms-v1@example.test', 'v1body'=>'Private enquiry body for native testing only.' ][$id] ?? ''; }
			public function set_result( $result ) { $this->result = $result; }
		};
		$native->run( $form );
		return [ $post, $form, (int) $GLOBALS['wpdb']->insert_id ];
	};
	[ $first, $form, $first_id ] = $make( 'Careers enquiry', 'careers' );
	[ $second, $other, $second_id ] = $make( 'Wholesale enquiry', 'wholesale' );
	$check( 'success' === ( $form->result['type'] ?? '' ) && 'success' === ( $other->result['type'] ?? '' ) && $first_id > 0 && $second_id > $first_id, 'Actual Bricks Save submission action persists two real page/form pairs' );
	for ( $arrival=0; $arrival<25; $arrival++ ) Bricks\Integrations\Form\Submission_Database::insert_data( [ 'post_id'=>$first, 'form_id'=>'v1form', 'form_data'=>[ 'v1body'=>[ 'type'=>'textarea','value'=>'Later synthetic arrival ' . $arrival ] ] ] );
	DSA\Bricks\Form_Identity_Service::confirmed();
	$check( $notices === [ $first_id, $second_id ], 'Exact committed native inserts remain confirmed after more than twenty later arrivals' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id>%d AND post_id=%d AND form_id=%s", $second_id, $first, 'v1form' ) );
	$check( (bool) wp_next_scheduled( 'dsa_admin_notification_event', [ 'form_submission', $first_id ] ), 'A confirmed saved form queues the existing deferred notification worker' );
	$record = DSA\Access\Form_Workspace_Service::notification_record( $first_id );
	$check( 'Careers enquiry' === $record['title'] && ! str_contains( wp_json_encode( $record ), 'forms-v1@example.test' ) && ! str_contains( wp_json_encode( $record ), 'Private enquiry body' ), 'Private notification projection uses saved native identity without enquiry answers or contact details' );
	$workspace = new DSA\Access\Form_Workspace_Service();
	$request = new WP_REST_Request( 'GET', '/dsa/v1/workspace/forms' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_header( 'Origin', home_url() );
	$request->set_header( 'Referer', admin_url() );
	$listing = $workspace->listing( $request )->get_data();
	$forms = array_column( $listing['forms'] ?? [], 'label', 'id' );
	$check( isset( $forms['native-' . $first . '-v1form'], $forms['native-' . $second . '-v1form'] ) && count( $forms ) === 2, 'Actual native SQL creates distinct automatic filters for equal form IDs on different pages' );
	$check( 'Careers enquiry' === $forms['native-' . $first . '-v1form'] && 'Wholesale enquiry' === $forms['native-' . $second . '-v1form'], 'Native bounded JSON identity projection preserves each real human form name' );
	$request->set_query_params( [ 'form'=>'native-' . $first . '-v1form' ] );
	$filtered = $workspace->listing( $request )->get_data();
	$check( count( $filtered['items'] ?? [] ) === 1 && $filtered['items'][0]['id'] === $first_id && 1 === $filtered['total'], 'Selecting a form filter scopes the exact native page/form pair' );
	$detail = new WP_REST_Request( 'GET', '/dsa/v1/workspace/forms/' . $first_id );
	$detail->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); $detail->set_header( 'Origin', home_url() ); $detail->set_url_params( [ 'id'=>$first_id ] );
	$private = $workspace->detail( $detail )->get_data();
	$encoded = wp_json_encode( $private );
	$check( str_contains( $encoded, 'Private enquiry body' ) && ! str_contains( $encoded, '_kiwe_form_identity_v1' ) && ! str_contains( $encoded, 'receipt' ), 'Authorized private detail shows business answers while omitting the internal identity and receipt' );
	$notifier->dispatch( 'form_submission', $first_id );
	$inbox = get_user_meta( 1, 'dsa_admin_notification_inbox', true );
	$inbox_text = wp_json_encode( $inbox );
	$check( str_contains( $inbox_text, 'form-submission-' . $first_id ) && ! str_contains( $inbox_text, 'forms-v1@example.test' ) && ! str_contains( $inbox_text, 'Private enquiry body' ), 'Existing role-derived team inbox receives the new form notice without private answers' );
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	$form->settings['submissionDupEntries'] = [ [ 'field_id'=>'v1mail' ] ];
	$native->run( $form );
	DSA\Bricks\Form_Identity_Service::confirmed();
	$check( 'error' === ( $form->result['type'] ?? '' ) && $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) && $notices === [ $first_id, $second_id ], 'A native duplicate rejection creates neither a saved record nor a false arrival notice' );
	wp_set_current_user( 0 );
	$check( ! $workspace->permission( $request ) && $workspace->listing( $request )->get_status() === 403, 'Anonymous visitors cannot read native form timelines or answers' );
	wp_set_current_user( 1 );
}

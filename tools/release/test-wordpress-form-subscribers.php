<?php
/** Installed lab only. The caller blocks external HTTP/mail/scheduling and supplies private receipts. */
function kiwe_form_subscriber_native_tests( callable $check, callable $ledger, string $run, string $mode ): array {
	global $wpdb;
	if ( PHP_SAPI !== 'cli' || home_url() !== 'https://plum-giraffe-539524.hostingersite.com' || ! preg_match( '/^[a-f0-9]{16}$/D', $run ) || ! in_array( $mode, [ 'normal','plain' ], true ) ) throw new RuntimeException( 'Exact disposable lab scope required' );
	$check( DSA_VERSION === '8.0.0-rc.471' && function_exists( 'pk_create_user_for_identifier' ), 'Installed candidate and native identity owner' );
	$before = [ 'dsa'=>get_option( 'dsa_settings' ), 'pk'=>get_option( 'pk_settings_v3' ), 'stp'=>get_option( 'stp_settings' ), 'apps'=>\DSA\Apps\Native_State::snapshot(), 'config'=>hash_file( 'sha256', ABSPATH . 'wp-config.php' ), 'engine'=>hash_file( 'sha256', DSA_DIR . '/includes/PhoneKey/phonekey-core.php' ) ];
	$check( 'normal' !== $mode || ( function_exists( 'WC' ) && class_exists( '\\Bricks\\Integrations\\Form\\Init' ) ), 'Normal fixture loads actual Woo and Bricks' );
	$actor = get_current_user_id(); $cookies = $_COOKIE; $server = $_SERVER; $owned_users = []; $rate_hashes = []; $ips = []; $post = 0; $form_rows = []; $failure = null;
	$prefix = 'kiwe_forms_' . $run . '_' . $mode;
	remove_all_actions( 'user_register' ); remove_all_actions( 'set_user_role' ); remove_all_actions( 'kiwe_link_enquiry_saved' ); remove_all_actions( 'kiwe_form_submission_saved' );
	$created = static function( $id ) use ( &$owned_users, $ledger, $mode ): void { $owned_users[] = (int) $id; $ledger( 'subscriber-users-' . $mode . '-' . count( $owned_users ), [ 'ids'=>$owned_users ] ); };
	add_action( 'user_register', $created );
	$set_ip = static function( int $index ) use ( $wpdb, $run, $mode, &$ips, &$rate_hashes, $check ): void {
		$last = 10 + ( hexdec( substr( $run, 0, 2 ) ) % 20 ) * 8 + ( 'plain' === $mode ? 4 : 0 ) + $index;
		$ip = '198.51.100.' . $last; $_SERVER['REMOTE_ADDR'] = $ip;
		$check( \DSA\Utilities\Origin_Checker::client_ip() === $ip, 'Fixture documentation address owns contact rate admission' );
		$hash = hash_hmac( 'sha256', 'form-subscriber|' . get_current_blog_id() . '|' . $ip, wp_salt( 'nonce' ) );
		$check( ! $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}dsa_rate_limits WHERE bucket_hash=%s", $hash ) ), 'New fixture contact-rate bucket' );
		$found = false; wp_cache_get( 'bucket_' . $hash, 'dsa_rate_limits', false, $found ); $check( ! $found, 'New fixture cache-rate bucket' ); $rate_hashes[] = $hash;
		if ( function_exists( 'stp_t' ) && stp_table_exists( 'ips' ) ) $check( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . stp_t( 'ips' ) . ' WHERE ip_address=%s', $ip ) ), 'Security fixture address has no original row' );
		$ips[] = $ip;
	};
	try {
		wp_set_current_user( 0 ); unset( $_COOKIE[\DSA\Identity\Form_Contact_Service::COOKIE] ); $set_ip( 0 );
		$email = $prefix . '@example.invalid'; $phone = '+1999' . sprintf( '%08d', hexdec( substr( $run, 0, 6 ) ) + ( 'plain' === $mode ? 1 : 0 ) );
		$check( ! pk_find_user( $email, 'email' ) && ! pk_find_user( $phone, 'phone' ), 'Synthetic contact pair is unused' );
		$ledger( 'subscriber-contacts-' . $mode, [ 'email'=>$email, 'phone'=>$phone ] );
		\DSA\Identity\Form_Contact_Service::capture( [ 'name'=>'Synthetic Subscriber','email'=>$email,'phone'=>$phone ] );
		$id = pk_find_user( $email, 'email' ); $user = $id ? get_userdata( $id ) : null;
		$check( $user && $user->roles === [ 'subscriber' ] && in_array( $id, $owned_users, true ), 'Actual native new contact becomes Subscriber' );
		$check( ! pk_account_verified( $id ) && ! pk_factor_verified( $id, 'email' ) && ! pk_factor_verified( $id, 'phone' ) && get_current_user_id() === 0, 'Native signup grants neither verification nor a sign-in session' );
		$check( pk_find_user( $phone, 'phone' ) === $id && pk_user_phone( $id ) === $phone, 'Native pending counterpart resolves only the new account' );
		$remembered = $_COOKIE[\DSA\Identity\Form_Contact_Service::COOKIE] ?? '';
		$own = \DSA\Identity\Form_Contact_Service::prefill();
		$check( $remembered && ! str_contains( $remembered, $email ) && $own['email'] === $email && $own['phone'] === $phone, 'Actual native encryption remembers only submitted browser contact' );
		$before_user = [ get_userdata( $id )->to_array(), get_user_meta( $id ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'factors' ) . ' WHERE user_id=%d ORDER BY id', $id ), ARRAY_A ) ];
		\DSA\Identity\Form_Contact_Service::capture( [ 'name'=>'Do not replace the account','email'=>$email ] );
		$check( count( $owned_users ) === 1 && $before_user === [ get_userdata( $id )->to_array(), get_user_meta( $id ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . pk_t( 'factors' ) . ' WHERE user_id=%d ORDER BY id', $id ), ARRAY_A ) ], 'Actual duplicate submission neither creates nor changes an existing account' );
		wp_set_current_user( $id );
		$check( \DSA\Identity\Form_Contact_Service::prefill()['email'] === $email && \DSA\Identity\Form_Contact_Service::prefill()['phone'] === $phone, 'Recognized pending native subscriber reuses its own contact' );
		$_COOKIE[\DSA\Identity\Form_Contact_Service::COOKIE] = $remembered;
		$other = wp_insert_user( [ 'user_login'=>$prefix . '_other', 'user_email'=>$prefix . '_other@example.invalid', 'user_pass'=>wp_generate_password( 40, true, true ), 'role'=>'subscriber' ] );
		if ( is_wp_error( $other ) ) throw new RuntimeException( 'Synthetic alternate actor refused' );
		wp_set_current_user( $other );
		$check( \DSA\Identity\Form_Contact_Service::prefill()['email'] === get_userdata( $other )->user_email, 'Another native actor cannot read previous browser contact' );
		wp_set_current_user( 0 ); \DSA\Identity\Form_Contact_Service::forget();
		$check( \DSA\Identity\Form_Contact_Service::prefill()['email'] === '', 'Native logout boundary clears weak remembered contact' );
		if ( class_exists( '\\Bricks\\Integrations\\Form\\Init' ) ) {
			$set_ip( 1 );
			$fields = [ [ 'id'=>'person','type'=>'text','autocomplete'=>'name' ], [ 'id'=>'reply','type'=>'email','autocomplete'=>'email' ], [ 'id'=>'telephone','type'=>'tel','autocomplete'=>'tel' ], [ 'id'=>'message','type'=>'textarea' ] ];
			$settings = [ 'fields'=>$fields, 'actions'=>[ 'save-submission' ], 'submissionFormName'=>'Synthetic subscriber form', '_attributes'=>[ [ 'name'=>'data-kiwe-form','value'=>'synthetic-subscriber' ] ] ];
			wp_set_current_user( \DSA\Access\Site_Owner_Service::owner_id() );
			$post = wp_insert_post( [ 'post_type'=>'page','post_status'=>'draft','post_title'=>$prefix . '_form','post_author'=>get_current_user_id() ], true ); if ( is_wp_error( $post ) ) throw new RuntimeException( 'Fixture form page refused' );
			$ledger( 'subscriber-form-' . $mode, [ 'postId'=>$post,'formId'=>'f471ab' ] );
			update_post_meta( $post, BRICKS_DB_PAGE_CONTENT, [ [ 'id'=>'f471ab','name'=>'form','parent'=>0,'children'=>[],'settings'=>$settings ] ] );
			wp_set_current_user( 0 );
			$resolved = \Bricks\Integrations\Form\Submission_Database::get_form_settings( $post, 'f471ab' );
			$ledger( 'subscriber-setup-diagnostic-' . $mode, [ 'nativeSettings'=>$resolved,'savedMeta'=>get_post_meta( $post, BRICKS_DB_PAGE_CONTENT, true ) ] );
			$check( \Bricks\Integrations\Form\Submission_Database::get_form_settings( $post, 'f471ab' ) === $settings, 'Actual saved native form settings resolve the fixture' );
			$check( has_filter( 'bricks/form/save-submission/form_data' ) !== false, 'Installed native form identity hook is registered' );
			$native = new \Bricks\Integrations\Form\Init(); $form_email = $prefix . '_form@example.invalid'; $phone = '+1999' . sprintf( '%08d', hexdec( substr( $run, 0, 6 ) ) + 3 );
			foreach ( [ 'form_settings'=>$settings,'form_fields'=>[ 'formId'=>'f471ab','postId'=>$post,'form-field-person'=>'Synthetic Form Visitor','form-field-reply'=>$form_email,'form-field-telephone'=>$phone,'form-field-message'=>'A native saved enquiry' ],'form_id'=>'f471ab','post_id'=>$post,'results'=>[],'uploaded_files'=>[] ] as $name=>$value ) ( new ReflectionProperty( $native, $name ) )->setValue( $native, $value );
			// Bricks2.4.3 stores the validated submission identity separately from raw fields.
			if ( property_exists( $native, 'form_element' ) ) {
				$element = \Bricks\Helpers::get_element_data( $post, 'f471ab' )['element'] ?? null;
				$check( is_array( $element ) && $element['id'] === 'f471ab', 'Native saved element owns the submission identity' );
				( new ReflectionProperty( $native, 'form_element' ) )->setValue( $native, $element );
			}
			$check( ! method_exists( $native, 'get_submission_id' ) || $native->get_submission_id() === 'f471ab', 'Native validated submission identity is initialized in the fixture' );
			$save = new \Bricks\Integrations\Form\Actions\Save_Submission( 'save-submission' ); $save->run( $native ); $row = (int) $wpdb->insert_id; if ( $row ) $form_rows[] = $row;
			$ledger( 'subscriber-save-diagnostic-' . $mode, [ 'row'=>$row,'results'=>$native->get_results(),'dbError'=>$wpdb->last_error,'tableExists'=>$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'bricks_form_submissions' ) ) ) === $wpdb->prefix . 'bricks_form_submissions' ] );
			$check( $row > 0 && ! empty( $native->get_results()['success'] ), 'Actual Bricks native insert succeeds' );
			\DSA\Bricks\Form_Contact_Adapter::response( [], $native );
			$check( $row > 0 && pk_find_user( $form_email, 'email' ) > 0, 'Real Bricks native save confirms subscriber before response' );
			$check( \DSA\Identity\Form_Contact_Service::prefill()['email'] === $form_email, 'Native saved website form fills the shared contact projection' );
			$before_count = count( $owned_users ); $failed = new \Bricks\Integrations\Form\Init();
			foreach ( [ 'form_settings'=>$settings,'results'=>[ 'error'=>[ [ 'message'=>'Fixture validation failed' ] ] ] ] as $name=>$value ) ( new ReflectionProperty( $failed, $name ) )->setValue( $failed, $value );
			\DSA\Bricks\Form_Contact_Adapter::response( [], $failed ); $check( count( $owned_users ) === $before_count, 'Failed native website form creates no subscriber' );
		}
		if ( \DSA\Apps\Store_App_Service::enabled() ) {
			$checkout = new \DSA\Commerce\Checkout_Service( new \DSA\Settings() );
			$check( $checkout->contact_checkout_value( 'native@example.invalid','billing_email' ) === 'native@example.invalid', 'Actual Woo value takes priority over form memory' );
			$check( $checkout->contact_checkout_value( null,'billing_phone' ) === $phone, 'Native checkout default reuses submitted phone without verification' );
		}
		$check( hash_file( 'sha256', DSA_DIR . '/includes/PhoneKey/phonekey-core.php' ) === '03ebbf97a5135a5f66ead3ea8aaafa54848edcfb0af2b92bd8c4687f493d46d0', 'PhoneKey engine remains byte-identical' );
	} catch ( Throwable $error ) { $failure = $error; }
	finally {
		wp_set_current_user( 0 ); require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $form_rows as $id ) $wpdb->delete( $wpdb->prefix . 'bricks_form_submissions', [ 'id'=>$id,'form_id'=>'f471ab' ] );
		if ( $post ) { $record = get_post( $post ); if ( ! $record || $record->post_title !== $prefix . '_form' ) throw new RuntimeException( 'Owned native fixture changed' ); wp_delete_post( $post, true ); }
		foreach ( array_reverse( array_unique( $owned_users ) ) as $id ) {
			$user = get_userdata( $id ); if ( ! $user || ( $user->user_email && ! str_starts_with( $user->user_email, $prefix ) ) ) throw new RuntimeException( 'Owned synthetic account mismatch' );
			foreach ( [ 'factors','activity','challenges','trusted_devices','visits','credentials' ] as $name ) $wpdb->delete( pk_t( $name ), [ 'user_id'=>$id ] );
			if ( ! wp_delete_user( $id ) || get_userdata( $id ) ) throw new RuntimeException( 'Synthetic account retirement failed' );
		}
		foreach ( $rate_hashes as $hash ) { $wpdb->delete( $wpdb->prefix . 'dsa_rate_limits', [ 'bucket_hash'=>$hash ] ); wp_cache_delete( 'bucket_' . $hash, 'dsa_rate_limits' ); }
		if ( function_exists( 'stp_t' ) && stp_table_exists( 'ips' ) ) foreach ( $ips as $ip ) {
			$ip_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . stp_t( 'ips' ) . ' WHERE ip_address=%s', $ip ) ); if ( ! $ip_id ) continue;
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . stp_t( 'sessions' ) . ' WHERE ip_id=%d', $ip_id ) ) as $session_id ) $wpdb->delete( stp_t( 'pages' ), [ 'session_id'=>(int) $session_id ] );
			$wpdb->delete( stp_t( 'events' ), [ 'ip_id'=>$ip_id ] ); $wpdb->delete( stp_t( 'sessions' ), [ 'ip_id'=>$ip_id ] ); $wpdb->delete( stp_t( 'ips' ), [ 'id'=>$ip_id,'ip_address'=>$ip ] );
		}
		remove_action( 'user_register', $created ); $_COOKIE = $cookies; $_SERVER = $server; wp_set_current_user( $actor );
		$check( $before === [ 'dsa'=>get_option( 'dsa_settings' ),'pk'=>get_option( 'pk_settings_v3' ),'stp'=>get_option( 'stp_settings' ),'apps'=>\DSA\Apps\Native_State::snapshot(),'config'=>hash_file( 'sha256', ABSPATH . 'wp-config.php' ),'engine'=>hash_file( 'sha256', DSA_DIR . '/includes/PhoneKey/phonekey-core.php' ) ], 'Original native settings, Apps, configuration and PhoneKey preserved' );
		$ledger( 'subscriber-cleanup-' . $mode, [ 'passed'=>true,'users'=>count( $owned_users ),'formRows'=>count( $form_rows ) ] );
	}
	if ( $failure ) throw $failure;
	return [ 'passed'=>true,'cleanup'=>true,'wordpress'=>$GLOBALS['wp_version'],'php'=>PHP_VERSION,'bricksLoaded'=>class_exists( '\\Bricks\\Integrations\\Form\\Init' ),'wooLoaded'=>function_exists( 'WC' ),'usersRetired'=>count( $owned_users ) ];
}

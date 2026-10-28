<?php
/** Native reader/permission regression; the existing isolation owner must supply temporary tables. */
if ( PHP_SAPI !== 'cli' || ! defined( 'KIWE_SEO_NATIVE_TEST' ) || true !== KIWE_SEO_NATIVE_TEST || empty( $GLOBALS['kiwe_seo_test_tables'] ) ) throw new RuntimeException( 'Owned native visibility fixture required.' );

function kiwe_pt1_native_run(): array {
	global $wpdb;
	$proof = [ 'passed' => false, 'cleanup' => false, 'checks' => [] ];
	$check = static function( bool $pass, string $label ) use ( &$proof ): void { $proof['checks'][$label] = $pass; if ( ! $pass ) throw new RuntimeException( $label ); };
	$hash = static fn( $value ): string => hash( 'sha256', serialize( $value ) );
	$marker = 'pt1_' . bin2hex( random_bytes( 6 ) );
	try {
		$check( home_url() === 'https://plum-giraffe-539524.hostingersite.com', 'Native visibility fixture is bound to the authorized lab' );
		$check( class_exists( DSA\Utilities\Content_Visibility::class ), 'Actual Core visibility owner is loaded' );
		$users = [];
		foreach ( [ 'administrator', 'author', 'subscriber' ] as $role ) {
			$id = wp_insert_user( [ 'user_login' => $marker . '_' . $role, 'user_email' => $marker . '_' . $role . '@example.invalid', 'user_pass' => wp_generate_password( 36, true, true ), 'role' => $role ] );
			$check( ! is_wp_error( $id ) && $id > 0, 'Temporary native ' . $role . ' created' ); $users[$role] = $id;
		}
		wp_set_current_user( $users['administrator'] );
		$ids = [];
		foreach ( [ 'public' => [ 'publish', '' ], 'protected' => [ 'publish', 'synthetic-only' ], 'private' => [ 'private', '' ], 'draft' => [ 'draft', '' ] ] as $label => [ $status, $password ] ) {
			$id = wp_insert_post( [ 'post_type' => 'post', 'post_status' => $status, 'post_password' => $password, 'post_title' => $marker . ' ' . $label, 'post_content' => $marker . '_body_' . $label, 'post_excerpt' => $marker . '_excerpt_' . $label, 'post_author' => $users['author'] ], true );
			$check( ! is_wp_error( $id ) && $id > 0, 'Temporary native ' . $label . ' post created' ); $ids[$label] = $id;
		}
		$settings = get_option( DSA_OPTION_SETTINGS, null );
		$profile = get_option( DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, null );
		$reader = new DSA\Site_Graph\Data_Query_Service(); $saved = new DSA\Saved\Saved_Items_Service();
		$args = [ 'resource' => 'posts', 'include' => array_values( $ids ), 'fields' => [ 'id', 'title', 'excerpt', 'content' ], 'limit' => 100 ];
		foreach ( [ 0, $users['subscriber'], $users['administrator'] ] as $actor ) {
			wp_set_current_user( $actor ); $response = $reader->query( $args, false );
			$check( array_column( $response['data'], 'id' ) === [ $ids['public'] ], 'Public native data returns only the unprotected post for actor ' . $actor );
			$check( ! str_contains( wp_json_encode( $response ), $marker . '_body_protected' ), 'Protected native body absent for actor ' . $actor );
		}
		wp_set_current_user( $users['administrator'] );
		$private = $reader->query( $args, true ); $actual = array_column( $private['data'], 'id' ); sort( $actual ); $expected = array_values( $ids ); sort( $expected );
		$check( $actual === $expected, 'Explicit native private administration retains all four records' );
		$public_only = $reader->query( $args + [ 'publicOnly' => true ], true );
		$check( array_column( $public_only['data'], 'id' ) === [ $ids['public'] ], 'Native administrator-budget reader remains public-only when requested' );
		$modules = new DSA\Modules\Module_Registry(); $modules->register_defaults();
		$context = new DSA\Site_Graph\Design_Context_Service( new DSA\AI\Site_Graph_Service( new DSA\Settings(), $modules ) );
		$collection = new ReflectionMethod( $context, 'collect' );
		$records = $collection->invoke( $context, 'posts', 200, [ 'include' => array_values( $ids ), 'fields' => [ 'id', 'content' ], 'publicOnly' => false ], true );
		$check( array_column( $records, 'id' ) === [ $ids['public'] ], 'Actual native Design Context collection cannot expand to protected content' );
		wp_set_current_user( $users['subscriber'] );
		foreach ( [ 'protected', 'private', 'draft' ] as $label ) {
			$before = $hash( get_user_meta( $users['subscriber'], 'dsa_saved_items', true ) );
			$result = $saved->mutate( 'add', [ 'id' => $ids[$label], 'title' => 'Untrusted fallback', 'url' => home_url( '/untrusted' ) ] );
			$check( empty( $result['ok'] ), 'Native subscriber cannot save inaccessible ' . $label );
			$check( $before === $hash( get_user_meta( $users['subscriber'], 'dsa_saved_items', true ) ), 'Rejected native ' . $label . ' save does not rewrite the list' );
		}
		$good = $saved->mutate( 'add', [ 'id' => $ids['public'] ] );
		$check( ! empty( $good['ok'] ) && $good['item']['id'] === $ids['public'], 'Ordinary native bookmark saves successfully' );
		$stored = get_user_meta( $users['subscriber'], 'dsa_saved_items', true );
		wp_set_current_user( $users['administrator'] ); wp_update_post( [ 'ID' => $ids['public'], 'post_status' => 'private' ] ); wp_set_current_user( $users['subscriber'] );
		$check( $saved->current_items() === [], 'Native publication change hides inaccessible enrichment' );
		$check( get_user_meta( $users['subscriber'], 'dsa_saved_items', true ) === $stored, 'Native hidden saved row remains exact' );
		wp_set_current_user( $users['administrator'] ); wp_update_post( [ 'ID' => $ids['public'], 'post_status' => 'publish' ] ); wp_set_current_user( $users['subscriber'] );
		$check( count( $saved->current_items() ) === 1, 'Native republishing restores the saved projection' );
		wp_set_current_user( $users['author'] );
		$check( ( $saved->normalize_item( [ 'id' => $ids['draft'] ] )['id'] ?? 0 ) === $ids['draft'], 'Native author retains readable own draft bookmark' );
		wp_set_current_user( $users['administrator'] );
		$check( ( $saved->normalize_item( [ 'id' => $ids['private'] ] )['id'] ?? 0 ) === $ids['private'], 'Native administrator retains readable private bookmark' );
		$deny = static fn( $required, $post ) => $post->ID === $ids['public'] ? true : $required;
		add_filter( 'post_password_required', $deny, PHP_INT_MAX, 2 ); wp_set_current_user( $users['subscriber'] );
		$check( $saved->normalize_item( [ 'id' => $ids['public'] ] ) === [] && ! DSA\Utilities\Content_Visibility::is_public( $ids['public'] ), 'Actual native password-denial filter remains effective' );
		remove_filter( 'post_password_required', $deny, PHP_INT_MAX );
		$check( get_option( DSA_OPTION_SETTINGS, null ) === $settings && get_option( DSA\Onboarding\Design_Context_Profile_Service::OPTION_PROFILE, null ) === $profile, 'Native visibility reads preserve settings and complete Design Context' );
		$check( ( $GLOBALS['kiwe_seo_test_http_calls'] ?? 0 ) === 0 && ( $GLOBALS['kiwe_seo_test_mail_calls'] ?? 0 ) === 0, 'Native boundary tests make no provider request or send' );
		$proof['runtime'] = DSA_VERSION; $proof['nativeUsers'] = count( $users ); $proof['nativePosts'] = count( $ids ); $proof['passed'] = true;
	} catch ( Throwable $error ) {
		$proof['error'] = $error->getMessage();
		$proof['frames'] = array_map( static fn( $frame ) => [ 'file' => basename( $frame['file'] ?? '' ), 'line' => $frame['line'] ?? 0, 'function' => $frame['function'] ?? '' ], array_slice( $error->getTrace(), 0, 5 ) );
	} finally {
		try { do_action( 'shutdown' ); remove_all_actions( 'shutdown' ); } catch ( Throwable $error ) { $proof['passed'] = false; $proof['shutdownError'] = $error->getMessage(); }
		kiwe_seo_test_cleanup( $proof );
	}
	$proof['passed'] = $proof['passed'] && $proof['cleanup'] && ! in_array( false, $proof['checks'], true );
	return $proof;
}
